# Silverstripe Algolia Module

[![Build Status](http://img.shields.io/travis/wilr/silverstripe-algolia.svg?style=flat-square)](http://travis-ci.org/wilr/silverstripe-algolia)
[![Version](http://img.shields.io/packagist/v/wilr/silverstripe-algolia.svg?style=flat-square)](https://packagist.org/packages/wilr/silverstripe-algolia)
[![License](http://img.shields.io/packagist/l/wilr/silverstripe-algolia.svg?style=flat-square)](LICENSE)

## Maintainer Contact

* Will Rossiter (@wilr) <will@fullscreen.io>

## Installation

```
composer require "wilr/silverstripe-alogolia"
```

## Documentation

Algolia’s search-as-a-service and full suite of APIs allow teams to easily
develop tailored, fast Search and Discovery experiences that delight and
convert.

This module adds the ability to sync Silverstripe pages to a Algolia Index.

Search results are provided via Algolia's InstantSearch.js widgets or
via their PHP SDK.

Indexing and removing documents is done transparently for any objects which
subclass `SiteTree` or by applying the
`Wilr\SilverStripe\Algolia\Extensions\AlgoliaObjectExtension` to your
DataObjects

### Indexing

If installing on a existing website run the `AlgoliaReindex` task (via CLI) 
to import existing pages.

```
./vendor/bin/sake dev/tasks/AlgoliaReindex
```

#### Customising the indexed fields

By default all `$db` fields and the title, id fields of any `$has_one`,
`$has_many` and `$many_many` fields as pushed to the index. To alter this, on
the subclass you wish to modify define the following:

```php
public function updateAlgoliaAttributes(SilverStripe\ORM\Map $attributes)
{
    $attributes->push('objectSpecialField', 'foobar');
}
```

To exclude attributes:

```php
public function shouldIncludeAttributeInAlgolia($attribute)
{
    if ($attribute === 'ShareTokenURL') {
        return false;
    }
}
```

#### Indexing DataObjects

**TODO**

#### Relationships

Out of the box, the default is to push the ID and Title fields of any
relationships (`$has_one`, `$has_many`, `$many_many`) into a field
`relation{name}`.

Additional fields from the relationship can be added via a PHP function.

```php
public function updateAlgoliaRelationshipAttributes(SilverStripe\ORM\Map $attributes, $related)
{
    $attributes->push('CategoryName', $related->CategoryName);
}
```

Or relationships can be excluded completely.

```php
public function shouldIncludeRelationshipInAlgolia($relationshipName)
{
    if ($relationshipName === 'ShareTokens') {
        return false;
    }
}
```

### Inspect Object Fields

To assist with debugging what fields will be pushed into Algolia and see what
information is already in Algolia use the `AlgoliaInspect` BuildTask. This can
be run via CLI

```
./vendor/bin/sake dev/tasks/AlgoliaInspect "ClassName=Page&ID=1"
```

Will output the Algolia data structure for the Page with the ID of '1'.

### Queued Indexing

To reduce the impact of waiting on a third-party service while publishing
changes, this module utilizes the `queued-jobs` module for uploading index
operations. The queuing feature can be disabled via the Config YAML.

```yaml
Wilr\SilverStripe\Algolia\Extensions\AlgoliaObjectExtension:
  use_queued_indexing: false
```

## TODO

- [ ] Build support with CMS as an optional add-on.
- [ ] Document and Finish support for DataObjects
- [ ] Document and Finish results controller modifications
