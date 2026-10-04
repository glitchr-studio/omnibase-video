---
title: Search
order: 8
---

# Search

`Search\VideoSearch::search($term, $filters, $page)` asks the
`typesense.finder.video` finder of glitchr/typesense-bundle - typo
tolerance, prefixes, the listed films only - and the database
(`VideoRepository::searchListed()`) when there is no such mapping or the
server does not answer. Filters: `category`, `channel` (slug), `duration`
(`short` < 4 min, `medium`, `long` > 20 min), `date` (`day`, `week`,
`month`, `year`), `sort` (`date`, `views`).

```yaml
# config/packages/typesense.yaml
typesense:
    mappings:
        video:
            connection: default
            class: 'Base\Video\Entity\Video'
            default_sorting_field: published
            fields:
                title:       { property: searchTitle, type: string, infix: true }
                text:        { property: searchText, type: string }
                channel:     { property: searchChannel, type: string }
                channelSlug: { property: searchChannelSlug, type: string, facet: true }
                tags:        { property: searchTags, type: 'string[]', facet: true }
                category:    { property: searchCategory, type: string, facet: true }
                duration:    { property: searchDuration, type: int32, facet: true }
                views:       { property: searchViews, type: int32 }
                published:   { property: searchPublished, type: int64 }
                listed:      { property: searchListed, type: bool, facet: true }
```

```bash
bin/console typesense:create          # the collection
bin/console typesense:action upsert   # every film into it
```

The index follows Doctrine afterwards. `Search\SafeIndexer` decorates the
bundle's listener: a search server that is down never fails a save, and no
visitor is told about it; `typesense:action upsert` catches up.
