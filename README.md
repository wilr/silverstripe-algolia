# :mag: Silverstripe Algolia Module

[![Build Status](http://img.shields.io/travis/wilr/silverstripe-algolia.svg?style=flat-square)](http://travis-ci.org/wilr/silverstripe-algolia)
[![Version](http://img.shields.io/packagist/v/wilr/silverstripe-algolia.svg?style=flat-square)](https://packagist.org/packages/wilr/silverstripe-algolia)
[![License](http://img.shields.io/packagist/l/wilr/silverstripe-algolia.svg?style=flat-square)](LICENSE)

## Maintainer Contact

* Will Rossiter (@wilr) <will@fullscreen.io>

## Installation

```
composer require "wilr/silverstripe-alogolia"
```

## Features

* :ballot_box_with_check: Supports multiple indexes and saving classes into multiple.
* :ballot_box_with_check: Integrates into existing versioned workflow.
* :ballot_box_with_check: No dependancies on the CMS, supports any DataObject subclass.
* :ballot_box_with_check: Queued job support for offloading operations to Algolia.
* :ballot_box_with_check: Easily configure search configuration and indexes via YAML and PHP.

## Documentation

Algolia’s search-as-a-service and full suite of APIs allow teams to easily
develop tailored, fast Search and Discovery experiences that delight and
convert.

This module adds the ability to sync Silverstripe pages to a Algolia Index.

Indexing and removing documents is done transparently for any objects which
subclass `SiteTree` or by applying the
`Wilr\SilverStripe\Algolia\Extensions\AlgoliaObjectExtension` to your
DataObjects.

## :hammer_and_wrench: Setting Up

First, sign up for Algolia.com account and install this module. Once installed,
Configure the API keys via YAML (environment variables recommended).

** :warning: this module will assume your indexes are setup as `dev_{IndexName}`,
`test_{IndexName}` and `live_{IndexName}` where the result of your environment
type is prefixed**

*app/_config/algolia.yml*
```yml
---
Name: algolia
After: silverstripe-algolia
---
SilverStripe\Core\Injector\Injector:
  Wilr\SilverStripe\Algolia\Service\AlgoliaService:
    properties:
      adminApiKey: '`ALGOLIA_ADMIN_API_KEY`'
      searchApiKey: '`ALGOLIA_SEARCH_API_KEY`'
      applicationId: '`ALGOLIA_SEARCH_APP_ID`'
      indexes:
        IndexName:
          includeClasses:
            - SilverStripe\CMS\Model\SiteTree
          indexSettings:
            attributesForFaceting:
              - 'filterOnly(ObjectClassName)'
```

Once the indexes and API keys are configured, run a `dev/build` to update the
database and refresh the indexSettings. Alternatively you can run
`AlgoliaConfigure` to manually rebuild the indexSettings.

### Defining Replica Indexes

If your search form provides a sort option (e.g latest or relevance) then you
will be using replica indexes (https://www.algolia.com/doc/guides/managing-results/refine-results/sorting/how-to/creating-replicas/)

These can be defined using the same YAML configuration.

```yml
---
Name: algolia
After: silverstripe-algolia
---
SilverStripe\Core\Injector\Injector:
  Wilr\SilverStripe\Algolia\Service\AlgoliaService:
    properties:
      adminApiKey: '`ALGOLIA_ADMIN_API_KEY`'
      searchApiKey: '`ALGOLIA_SEARCH_API_KEY`'
      applicationId: '`ALGOLIA_SEARCH_APP_ID`'
      indexes:
        IndexName:
          includeClasses:
            - SilverStripe\CMS\Model\SiteTree
          indexSettings:
            attributesForFaceting:
              - 'filterOnly(ObjectClassName)'
            replicas:
              - IndexName_Latest
        IndexName_Latest:
          indexSettings:
            ranking:
              - 'desc(objectCreated)'
              - 'typo'
              - 'words'
              - 'filters'
              - 'proximity'
              - 'attribute'
              - 'exact'
              - 'custom'
```

## Indexing

If installing on a existing website run the `AlgoliaReindex` task (via CLI)
to import existing data. This will batch import all the records from your
database into the indexes configured above.

```
./vendor/bin/sake dev/tasks/AlgoliaReindex "flush=1"
```

Individually records will be indexed automatically going forward via the
`onAfterPublish` hook and removed via the `onAfterUnpublish` hook which is
called when publishing or unpublishing a document. If your DataObject does not
implement the `Versioned` extension you'll need to manage this state yourself
by calling `$item->indexInAlgolia()` and `$item->removeFromAlgolia()`.

`AlgoliaReindex` takes a number of arguments to allow for customisation of bulk
indexing. For instance, if you have a large amount of JobVacancies to bulk
import but only need the active ones you can trigger the task as follows:

```
/vendor/bin/sake dev/tasks/AlgoliaReindex "onlyClass=Vacancy&filter=ExpiryDate>NOW()"
```

### Customising the indexed attributes (fields)

By default only `ID`, `Title` and `Link`, `LastEdited` will be indexed from
each record. To specify additional fields, define a `algolia_index_fields`
config variable.

```php
class MyPage extends Page {
    // ..
    private static $algolia_index_fields = [
        'Content',
        'MyCustomColumn',
        'RelationshipName'
    ];
}
```

### Customising the indexed relationships

Out of the box, the default is to push the ID and Title fields of any
relationships (`$has_one`, `$has_many`, `$many_many`) into a field
`relation{name}` with the record `ID` and `Title` as per the behaviour with
records.

Additional fields from the relationship can be indexed via a PHP function

```php
public function updateAlgoliaRelationshipAttributes(\SilverStripe\ORM\Map $attributes, $related)
{
    $attributes->push('CategoryName', $related->CategoryName);
}
```

### Excluding an object from indexing

Objects can define a `canIndexInAlgolia` method which should return false if the
object should not be indexed in algolia.

```php
public function canIndexInAlgolia()
{
    if ($this->Expired) {
        return false;
    }
}
```

### Queued Indexing

To reduce the impact of waiting on a third-party service while publishing
changes, this module utilizes the `queued-jobs` module for uploading index
operations. The queuing feature can be disabled via the Config YAML.

```yaml
Wilr\SilverStripe\Algolia\Extensions\AlgoliaObjectExtension:
  use_queued_indexing: false
```

## Displaying and fetching results

For your website front-end you can use InstantSearch.js libraries if you wish,
or to fetch a `PaginatedList` of results from Algolia, create a method on your
`Controller` subclass to call `Wilr\SilverStripe\Algolia\Service\AlgoliaQuerier`

```php
use Wilr\SilverStripe\Algolia\Service\AlgoliaQuerier;

class PageController extends ContentController
{
    public function results()
    {
        $results = Injector::inst()->get(AlgoliaQuerier::class)->fetchResults(
            'indexName',
            $this->request->getVar('search'), [
                'page' => $request->getVar('start') ?: 0,
                'hitsPerPage' => 25
            ]
        );

        return [
            'Title' => 'Search Results',
            'Results' => $results
        ]
    }
}
```

## :mag: Inspect Object Fields

To assist with debugging what fields will be pushed into Algolia and see what
information is already in Algolia use the `AlgoliaInspect` BuildTask. This can
be run via CLI

```
./vendor/bin/sake dev/tasks/AlgoliaInspect "ClassName=Page&ID=1"
```

Will output the Algolia data structure for the Page with the ID of '1'.


## TODO

- [ ] Document and Finish results controller modifications
