import $ from 'jquery';
import { searchBox, hits, pagination, stats, configure, sortBy } from 'instantsearch.js/es/widgets'

const algoliasearch = require('algoliasearch');
const instantsearch = require('instantsearch.js').default;


export default function () {
    let client;

    const url = new URL(window.location.href);
    const searchParameter = url.searchParams.get("Search");
    const pageParameter = url.searchParams.get("Page");

    $(document).ready(() => {
        loadDetails(getConfig());

        // on click of any of the results in the list. Add the query into it
        // so that on the other end we can pick up and anchor the user to it.
        console.log('hi handle');

        $('body').on('click', '.article-list .ais-Hits-item a', function(e) {
            e.preventDefault();

            var location = $(this).attr('href');
            var query = $('.ais-SearchBox-input').val();

            if (query) {
                location += '#sh-' + encodeURIComponent(query);
            }

            window.location.href = location;

            return false;
        })
    });

    function loadDetails(config) {
        client = algoliasearch(config.applicationID, config.apiKey);

        const state = {};
        state[config.indexName] = {
            query: searchParameter,
            page: pageParameter,
        };

        const search = instantsearch({
            indexName: config.indexName,
            searchClient: client,
            initialUiState: state,
            routing: {

            }
        });

        search.addWidgets([
            configure({
                attributesToSnippet: ['objectForTemplate'],
            }),
            stats({
                container: '.search-results .stats'
            }),
            hits({
              container: '.search-results .results',
              attributesToSnippet: [
                  'objectForTemplate'
              ],
              templates: {
                empty: `Sorry, your search query did not return any results.`,
                item: `
                  <article>
                    <h2 class="hit-name">
                      <a href="{{ objectLink }}">{{#helpers.highlight}}{ "attribute": "objectTitle" }{{/helpers.highlight}}</a>
                    </h2>

                    <div class="hit-description">
                      {{#helpers.snippet}}{ "attribute": "objectForTemplate" }{{/helpers.snippet}}
                    </div>

                    <p class="hit-link"><a href="{{ objectLink }}">{{ objectLink }}</a></div>
                  </article>
                `,
              },
            }),
            searchBox({
                container: '.search-results > .box',
                showReset: false,
                showLoadingIndicator: true,
                placeholder: 'Search Keywords'
            }),
            pagination({
                container: '.search-results > .pagination',
            }),
            sortBy({
                container: '.search-results .sort-by',
                items: [
                  { label: 'Relevance', value: config.indexName },
                  { label: 'Last Updated (desc)', value: config.indexName + '_last_updated' }
                ],
            })
        ]);

        search.start();
    }
}
