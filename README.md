# Silverstripe Algolia Module

[![Build Status](http://img.shields.io/travis/wilr/silverstripe-algolia.svg?style=flat-square)](http://travis-ci.org/wilr/silverstripe-algolia)
[![Version](http://img.shields.io/packagist/v/wilr/silverstripe-algolia.svg?style=flat-square)](https://packagist.org/packages/wilr/silverstripe-algolia)
[![License](http://img.shields.io/packagist/l/wilr/silverstripe-algolia.svg?style=flat-square)](LICENSE.md)

## Maintainer Contact

* Will Rossiter (@wilr) <will@fullscreen.io>

## Installation

> composer require "wilr/silverstripe-alogolia"

## Documentation

This module adds the ability to sync an Alogolia index with your Silverstripe
pages and provides the results via Algolia's InstantSearch.js widgets or via
a PHP API

Indexing and removing documents is done transparently for any objects which
subclass `SiteTree`.

### Indexing

#### Indexing DataObjects

**TODO**

#### Customising the indexed Fields

By default all `$db` fields and the title, id fields of any `$has_one`,
`$has_many` and `$many_many` fields as pushed to the index. To alter this, on
the subclass you wish to modify define the following.

    ```php
    public function updateAlgoliaFields(SilverStripe\ORM\Map $fields)
    {
        $fields->push('objectSpecialField', 'foobar');
    }
    ```

#### Relationships

Out of the box, the default is to push the ID of any relationships (`$has_one`,
`$has_many`, `$many_many`) as an array into a field `relation{name}IDs` and

## TODO

- [ ] Support multiple indexes, select index per indexed class.
- [ ] Build support with CMS as an optional dependancy.
- [ ] Queued, async publishing to Algolia.