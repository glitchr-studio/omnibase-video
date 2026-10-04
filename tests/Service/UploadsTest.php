<?php

namespace Base\Video\Tests\Service;

use Base\Entity\User;
use Base\Video\Repository\VideoRepository;
use Base\Video\Service\Channels;
use Base\Video\Service\Uploads;
use Base\Video\Tests\Member;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

class UploadsTest extends TestCase
{
    private function user(int $id, bool $verified = true): User
    {
        return Member::make($id, $verified);
    }

    private function uploads(?User $found = null, int $sent = 0, array $options = [], ?array &$dispatched = null): Uploads
    {
        $users = $this->createStub(EntityRepository::class);
        $users->method('find')->willReturn($found);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($users);
        $videos = $this->createStub(VideoRepository::class);
        $videos->method('sumUploads')->willReturn($sent);
        $bus = new class($dispatched) implements MessageBusInterface {
            public function __construct(private ?array &$log)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->log[] = $message;

                return new Envelope($message);
            }
        };

        return new Uploads($em, $videos, $this->createStub(Channels::class), $bus,
            new RateLimiterFactory(['id' => 'video_upload', 'policy' => 'fixed_window', 'limit' => 2, 'interval' => '1 day'], new InMemoryStorage()),
            'secret', ...($options + ['enabled' => true, 'quota' => 1000, 'maxSize' => 600, 'verifiedOnly' => true, 'directory' => sys_get_temp_dir(), 'ttl' => 3600]));
    }

    public function testATokenSaysWhoUntilWhenAndWhichFilm(): void
    {
        $uploads = $this->uploads();
        $token = $uploads->token($this->user(4), null, 1000);

        self::assertSame(['u' => 4, 'e' => 4600, 'r' => null], $uploads->verify($token, 2000));
        self::assertNull($uploads->verify($token, 5000), 'expired');
        self::assertNull($uploads->verify(substr($token, 0, -2).'xx', 2000), 'forged signature');
        [$payload, $signature] = explode('.', $token);
        $other = rtrim(strtr(base64_encode(json_encode(['u' => 1, 'e' => 4600, 'r' => null])), '+/', '-_'), '=');
        self::assertNull($uploads->verify($other.'.'.$signature, 2000), 'another member\'s payload under this signature');
        self::assertNull($uploads->verify(null));
        self::assertNull($uploads->verify('nonsense'));
    }

    public function testPreCreateRefusesWithoutAValidToken(): void
    {
        $answer = $this->uploads()->hook(['Type' => 'pre-create', 'Event' => ['Upload' => ['Size' => 10, 'MetaData' => ['token' => 'forged.token']]]]);
        self::assertTrue($answer['RejectUpload']);
        self::assertSame(403, $answer['HTTPResponse']['StatusCode']);
        self::assertSame('upload.refused.token', $answer['HTTPResponse']['Body']);
    }

    public function testPreCreateChecksTheMemberTheSizeTheQuotaAndTheLimiter(): void
    {
        $member = $this->user(4);
        $uploads = $this->uploads($member, 700);
        $hook = fn (int $size) => ['Type' => 'pre-create', 'Event' => ['Upload' => ['Size' => $size, 'MetaData' => ['token' => $uploads->token($member)]]]];

        self::assertSame('upload.refused.size', $uploads->hook($hook(601))['HTTPResponse']['Body']);
        self::assertSame('upload.refused.quota', $uploads->hook($hook(301))['HTTPResponse']['Body'], '700 sent of 1000');
        self::assertSame([], $uploads->hook($hook(300)), 'accepted');
        self::assertSame([], $uploads->hook($hook(300)));
        self::assertSame('upload.refused.limit', $uploads->hook($hook(300))['HTTPResponse']['Body'], 'two a day here');
        self::assertSame(300, $uploads->remaining($member));

        $unverified = $this->user(5, false);
        $strict = $this->uploads($unverified);
        self::assertSame('upload.refused.verified', $strict->refusal($unverified, 10));
        self::assertNull($this->uploads($unverified, 0, ['verifiedOnly' => false])->refusal($unverified, 10));

        $closed = $this->uploads($member, 0, ['enabled' => false]);
        $answer = $closed->hook(['Type' => 'pre-create', 'Event' => ['Upload' => ['Size' => 1, 'MetaData' => ['token' => $closed->token($member)]]]]);
        self::assertSame(404, $answer['HTTPResponse']['StatusCode'], 'uploads off: as if tusd were not there');
    }

    public function testPostFinishOnlyTakesAFileOfTheUploadsDirectory(): void
    {
        $member = $this->user(4);
        $dispatched = [];
        $uploads = $this->uploads($member, 0, [], $dispatched);
        $uploads->hook(['Type' => 'post-finish', 'Event' => ['Upload' => ['ID' => 'abc', 'Size' => 10, 'MetaData' => ['token' => $uploads->token($member), 'filename' => 'x.mp4'], 'Storage' => ['Path' => '/etc/passwd']]]]);
        self::assertSame([], $dispatched ?? [], 'a path outside the uploads: nothing is transcoded');
        self::assertFalse($uploads->isUploadPath('/etc/passwd'));
        self::assertFalse($uploads->isUploadPath(sys_get_temp_dir().'/../etc/passwd'));

        $file = tempnam(sys_get_temp_dir(), 'tus');
        self::assertTrue($uploads->isUploadPath($file));
        unlink($file);
    }

    public function testAnAddressPartIsLowercaseHex(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', Uploads::slug());
    }
}
