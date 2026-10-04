<?php

namespace Base\Video\DependencyInjection;

use Base\Bundle\AbstractBaseConfiguration;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

/**
 * config/packages/video.yaml. Every leaf is also a flat parameter
 * (video.upload.enabled, video.suggest.provider...): docs/configuration.md.
 */
class VideoConfiguration extends AbstractBaseConfiguration
{
    private bool $childrenDeclared = false;

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = $this->getTreeBuilder();
        if ($this->childrenDeclared) {
            return $treeBuilder;
        }
        $this->childrenDeclared = true;

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('storage')->defaultValue('%kernel.project_dir%/var/storage/videos')
                    ->info('Where the transcoder writes a video\'s playlists, segments, poster and storyboard (one directory a video).')->end()
                ->scalarNode('public_path')->defaultValue('/media/videos')
                    ->info('The address the web server serves that directory at (nginx alias).')->end()
                ->arrayNode('upload')->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->info('Members send their films (the studio\'s upload page and tusd\'s hooks). Off: a broadcaster\'s site, the videos come from the back office or an import.')->end()
                        ->booleanNode('auto_publish')->defaultFalse()->info('A transcoded upload goes online at once with the visibility chosen; off, it waits as a draft for its author.')->end()
                        ->integerNode('quota')->defaultValue(20 * 1024 ** 3)->info('Bytes a member may have sent in all (the originals); 0: no quota.')->end()
                        ->integerNode('max_size')->defaultValue(8 * 1024 ** 3)->info('Bytes one file may weigh.')->end()
                        ->booleanNode('verified_only')->defaultTrue()->info('Only a member whose e-mail is verified may send.')->end()
                        ->scalarNode('directory')->defaultValue('%kernel.project_dir%/var/storage/uploads')->info('Where tusd keeps the uploads (its -upload-dir, as the site sees it).')->end()
                        ->scalarNode('endpoint')->defaultValue('/files/')->info('tusd\'s address, through the proxy.')->end()
                        ->integerNode('token_ttl')->defaultValue(86400)->info('Seconds an upload token stays valid (a long film over a slow line resumes the next day).')->end()
                        ->integerNode('keep_originals')->defaultValue(0)->info('Days an original is kept after its transcoding (replacing the file, a new ladder); 0: removed at once.')->end()
                    ->end()
                ->end()
                ->arrayNode('transcode')->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('ffmpeg')->defaultValue('ffmpeg')->end()
                        ->scalarNode('ffprobe')->defaultValue('ffprobe')->end()
                        ->arrayNode('renditions')
                            ->info('The ladder, by height: a source is never scaled up (a 720p film gets 720, 480, 360).')
                            ->integerPrototype()->end()
                            ->defaultValue([1080, 720, 480, 360])
                        ->end()
                        ->integerNode('segment')->defaultValue(6)->info('Seconds a segment lasts.')->end()
                        ->scalarNode('preset')->defaultValue('veryfast')->info('x264\'s preset.')->end()
                        ->integerNode('timeout')->defaultValue(4 * 3600)->info('Seconds one transcoding may take.')->end()
                        ->integerNode('thumbnails')->defaultValue(100)->info('Pictures of the storyboard (the time bar\'s preview), at most.')->end()
                        ->integerNode('frames')->defaultValue(4)->info('Stills offered as a poster in the studio.')->end()
                    ->end()
                ->end()
                ->arrayNode('views')->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('min_seconds')->defaultValue(30)->info('A view counts after min(this, ratio x duration) seconds watched.')->end()
                        ->floatNode('ratio')->defaultValue(0.5)->end()
                        ->integerNode('dedupe')->defaultValue(6 * 3600)->info('Seconds during which a visitor counts once for a video.')->end()
                    ->end()
                ->end()
                ->arrayNode('comments')->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('auto_approve')->defaultTrue()->info('A comment Akismet finds clean goes online at once; off, every comment waits for the moderators.')->end()
                        ->booleanNode('guests')->defaultFalse()->info('Visitors without an account may comment.')->end()
                    ->end()
                ->end()
                ->arrayNode('search')->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('external')->defaultFalse()->info('Reserved for a search across the platforms (glitchr/omnishow, not built): off.')->end()
                        ->scalarNode('finder')->defaultValue('typesense.finder.video')->info('The typesense-bundle finder of the "video" mapping; without it, or with the server down, the database answers.')->end()
                    ->end()
                ->end()
                ->arrayNode('suggest')->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('provider')->values(['local', 'gorse'])->defaultValue('local')->info('local: the Related table computed at night; gorse: gorse-in-one first, the local one when it fails.')->end()
                        ->scalarNode('gorse_url')->defaultValue('')->info('http://suggest:8088; empty: Gorse is off whatever the provider.')->end()
                        ->scalarNode('gorse_key')->defaultValue('')->info('Its server API key (the back office\'s API keys page wins: api.gorse.key).')->end()
                        ->integerNode('per_channel')->defaultValue(2)->info('Suggestions from one channel at most, in one list.')->end()
                    ->end()
                ->end()
                ->arrayNode('remote')->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('hosts')
                            ->info('Hosts a "remote-hls" source\'s master playlist may be read from by the manifest route (no copy: the variants and segments are read by the browser).')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                        ->integerNode('ttl')->defaultValue(3600)->info('Seconds a master playlist read is kept.')->end()
                    ->end()
                ->end()
                ->arrayNode('categories')
                    ->info('The categories a video may be filed under (keys of video.category.<key> in the translations).')
                    ->scalarPrototype()->end()
                    ->defaultValue(['music', 'film', 'animation', 'documentary', 'education', 'science', 'sport', 'games', 'travel', 'cooking', 'news', 'comedy', 'diy', 'other'])
                ->end()
                ->arrayNode('licenses')
                    ->scalarPrototype()->end()
                    ->defaultValue(['standard', 'cc-by', 'cc-by-sa', 'cc-by-nc', 'cc0'])
                ->end()
            ->end()
        ->end();

        return $treeBuilder;
    }
}
