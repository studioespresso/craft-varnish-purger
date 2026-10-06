# Changelog

## 1.1.0 - 2026-10-06
- Assets relation fields are tagged with the element that owns the field instead of `a:any`
- Structure queries (`ancestors`, `children`, `siblings`) are scoped to their section or category group instead of `e:any`/`c:any`
- Neo: nested blocks and field values are scoped to `field-owner:{field}-{owner}`, and the type-wide ban after Neo rebuilds a block structure is skipped

## 1.0.0 - 2026-10-04
- Initial release
