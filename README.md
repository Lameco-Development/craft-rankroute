# RankRoute for Craft CMS

The Craft side of RankRoute, Laméco's SEO content pipeline in n8n. One plugin replacing
`lameco/craft-entry-optimizer` and `lameco/craft-seo-import`.

## Requirements

- PHP 8.2 or later
- Craft CMS 5.8.0 or later
- SEOmatic (optional; required for the bulk meta endpoint)

## Installation

```bash
composer require lameco/craft-rankroute
php craft plugin/install rankroute
```

Set the API key in `.env`:

```dotenv
RANKROUTE_API_KEY=your-secret-key
```

## Endpoints

Documented per endpoint as they land; see `CONTEXT.md` for the vocabulary and
`docs/adr/` for the decisions.

## Development

```bash
composer check-cs
composer phpstan
composer test        # needs tests/.env, see tests/.env.example
```
