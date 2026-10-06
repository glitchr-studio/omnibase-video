<?php

namespace Base\Video\Tests\Demo;

use Base\Demo\DemoAccountRegistry;
use Base\Video\Demo\VideoDemoAccounts;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Role\RoleHierarchy;
use Symfony\Component\Yaml\Yaml;

/**
 * The demonstration accounts of a video platform: a creator, a member, the
 * moderators, the catalogue - a label and a sentence for each in the
 * bundle's catalogues, and nobody who reaches the super-administrator,
 * whatever the application's hierarchy.
 */
class VideoDemoAccountsTest extends TestCase
{
    /** A platform's hierarchy: the moderators below the administrators (docs/moderation.md). */
    private const PLATFORM = [
        'ROLE_STAFF' => ['ROLE_USER'],
        'ROLE_EDITOR' => ['ROLE_STAFF'],
        'ROLE_ADMIN' => ['ROLE_EDITOR'],
        'ROLE_SUPERADMIN' => ['ROLE_ADMIN', 'ROLE_ALLOWED_TO_SWITCH'],
    ];

    /** omnibase's usual hierarchy: the editors are the site's makers, above its super-administrator. */
    private const MAKERS = [
        'ROLE_ADMIN' => ['ROLE_USER'],
        'ROLE_SUPERADMIN' => ['ROLE_ADMIN', 'ROLE_ALLOWED_TO_SWITCH'],
        'ROLE_EDITOR' => ['ROLE_SUPERADMIN'],
    ];

    protected function setUp(): void
    {
        if (!class_exists(DemoAccountRegistry::class)) {
            self::markTestSkipped('Requires a glitchr/omnibase with the demo environment.');
        }
    }

    private function registry(array $hierarchy = self::PLATFORM): DemoAccountRegistry
    {
        $roles = new RoleHierarchy($hierarchy);

        return new DemoAccountRegistry([new VideoDemoAccounts($roles)], [], $roles);
    }

    public function testOneAccountForEachOfThePlatformsPeople(): void
    {
        $accounts = $this->registry()->all();

        self::assertSame(['createur', 'membre', 'moderation', 'admin'], array_keys($accounts));
        self::assertSame(['ROLE_USER'], $accounts['createur']->getAllRoles(), 'a creator is a member: the studio is every member\'s');
        self::assertSame(['ROLE_USER'], $accounts['membre']->getAllRoles());
        self::assertSame(['ROLE_EDITOR'], $accounts['moderation']->getAllRoles());
        self::assertSame(['ROLE_ADMIN'], $accounts['admin']->getAllRoles());
        self::assertSame('createur', $accounts['createur']->getPassword(), 'the password is the identifier, as in the fixtures');
    }

    public function testNobodyReachesTheSuperAdministrator(): void
    {
        $registry = $this->registry();
        foreach ($registry->all() as $account) {
            self::assertFalse($registry->reachesSuperAdmin($account->getAllRoles()), $account->identifier);
        }
    }

    public function testWhereTheEditorsAreAboveTheSuperAdministratorTheModeratorIsLeftOut(): void
    {
        // The registry refuses a declaration whose roles reach ROLE_SUPERADMIN: it is not made at all.
        $accounts = $this->registry(self::MAKERS)->all();

        self::assertSame(['createur', 'membre', 'admin'], array_keys($accounts));
    }

    public function testEachHasItsLabelAndItsSentenceInTheCatalogues(): void
    {
        foreach (['fr', 'en'] as $locale) {
            $catalogue = Yaml::parseFile(\dirname(__DIR__, 2).'/translations/video+intl-icu.'.$locale.'.yaml')['demo'];

            foreach ($this->registry()->all() as $identifier => $account) {
                self::assertSame('@video.demo.'.$identifier.'.label', $account->label);
                self::assertSame('@video.demo.'.$identifier.'.description', $account->description);
                self::assertNotEmpty($catalogue[$identifier]['label'] ?? null, "$identifier in $locale");
                self::assertNotEmpty($catalogue[$identifier]['description'] ?? null, "$identifier in $locale");
            }
        }
    }
}
