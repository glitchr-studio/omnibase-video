<?php

namespace Base\Video\Controller\Admin;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;

/**
 * The platform's screens are written by its staff - the administrators
 * (catalogue) and, for moderation, the moderators (ROLE_EDITOR) - not only
 * by the super-administrator omnibase/admin requires by default for
 * anything that writes. $custom names the screen's own actions.
 */
trait OpenToEditorsTrait
{
    protected function openTo(Actions $actions, string $role, string ...$custom): Actions
    {
        return $actions->setPermissions(array_fill_keys(array_merge([
            Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE,
            Action::SAVE_AND_RETURN, Action::SAVE_AND_CONTINUE, Action::SAVE_AND_ADD_ANOTHER,
        ], $custom), $role));
    }
}
