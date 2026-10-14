# Changelog

## 6.0.0-beta.01 - Unreleased
- Craft CMS 6 (Laravel) support; Craft 5 stays on 1.x
- Settings come from `config/craft/varnish.php` (a Laravel config file, use `env()`), Craft 5's per-environment format is gone
- Console commands are `varnish:check` and `varnish:purge` (`varnish/check` and `varnish/purge` still work)
- Tagged pages are sent with `Cache-Control: public`, since Laravel marks every response `private` by default
- ESI fragment URLs are signed with the app key
- Removed: category scoping (categories are a legacy Yii2 adapter feature in Craft 6) and the Neo integration (Neo doesn't support Craft 6 yet)

## 1.1.0 - 2026-10-06
- Assets relation fields are tagged with the element that owns the field instead of `a:any`
- Structure queries (`ancestors`, `children`, `siblings`) are scoped to their section or category group instead of `e:any`/`c:any`
- Neo: nested blocks and field values are scoped to `field-owner:{field}-{owner}`, and the type-wide ban after Neo rebuilds a block structure is skipped

## 1.0.0 - 2026-10-04
- Initial release
