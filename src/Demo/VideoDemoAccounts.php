<?php

namespace Base\Video\Demo;

use Base\Demo\DemoAccount;
use Base\Demo\DemoAccountProviderInterface;
use Base\Demo\DemoAccountRegistry;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * The demonstration accounts of a video platform (glitchr/omnibase's `demo`
 * environment): one for each of its people. A creator and a member hold no
 * role - every member has the studio (ROLE_USER); what tells them apart is
 * what the fixtures give them: a channel, its films and their statistics to
 * the first, subscriptions, a list and comments to the second. The
 * moderators are ROLE_EDITOR (VideoVoter::MODERATE: the comments, the
 * reports, the take-downs), the catalogue is ROLE_ADMIN's (every film and
 * channel, in the back office).
 *
 * In an application whose ROLE_EDITOR stands above its super-administrator
 * (omnibase's usual hierarchy, where the editors are the site's makers) the
 * moderator is left out: a super-administrator is never a demonstration
 * account, and the registry would refuse the declaration.
 *
 * An application's fixtures take them from Base\Demo\DemoAccountFactory
 * ($accounts->account('createur', $manager)) and attach what makes them
 * worth signing in as. A platform without one of them leaves it out:
 * base.demo.exclude.
 *
 * Registered when the installed glitchr/omnibase has the demo environment
 * (config/services.php).
 */
final class VideoDemoAccounts implements DemoAccountProviderInterface
{
    public function __construct(private readonly ?RoleHierarchyInterface $roleHierarchy = null)
    {
    }

    public function getDemoAccounts(): iterable
    {
        yield new DemoAccount('createur', '@video.demo.createur.label', '@video.demo.createur.description', position: 10);
        yield new DemoAccount('membre', '@video.demo.membre.label', '@video.demo.membre.description', position: 20);
        if (!$this->reachesSuperAdmin('ROLE_EDITOR')) {
            yield new DemoAccount('moderation', '@video.demo.moderation.label', '@video.demo.moderation.description', roles: ['ROLE_EDITOR'], position: 30);
        }
        yield new DemoAccount('admin', '@video.demo.admin.label', '@video.demo.admin.description', roles: ['ROLE_ADMIN'], position: 40);
    }

    private function reachesSuperAdmin(string $role): bool
    {
        return \in_array(DemoAccountRegistry::SUPER_ADMIN, $this->roleHierarchy?->getReachableRoleNames([$role]) ?? [$role], true);
    }
}
