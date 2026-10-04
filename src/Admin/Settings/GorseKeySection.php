<?php

namespace Base\Video\Admin\Settings;

use Base\Admin\Settings\SettingsSectionInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Gorse's server key (video.suggest.provider: gorse), on the API keys page: typed there it wins over the configuration. */
#[AsTaggedItem(priority: 50)]
final class GorseKeySection implements SettingsSectionInterface
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function getPage(): string
    {
        return self::API_KEYS;
    }

    public function getFields(): array
    {
        return [
            'api.gorse.key' => ['required' => false, 'label' => $this->translator->trans('admin.settings.gorse_key', [], 'video')],
        ];
    }
}
