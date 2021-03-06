<?php

namespace Eloquent\ElasticScout;

use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use Elasticsearch\Client as Elastic;
use Illuminate\Database\Eloquent\Collection;
use Elasticsearch\Common\Exceptions\Missing404Exception;

class ElasticScoutEngine extends Engine
{
    /**
     * Elastic where the instance of Elastic|\Elasticsearch\Client is stored.
     *
     * @var object
     */
    protected $elastic;

    /**
     * Create a new engine instance.
     *
     * @param  \Elasticsearch\Client  $elastic
     * @return void
     */
    public function __construct(Elastic $elastic)
    {
        $this->elastic = $elastic;
    }

    /**
     * Update the given model in the index.
     *
     * @param  Collection $models
     * @return void
     */
    public function update($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        $model = $models->first();

        try {
            $this->elastic->indices()->get(['index' => $model->searchableAs()]);
        } catch (Missing404Exception $exception) {
            // index doesnt exist... we need to create it...
            $params = ['index' => $model->searchableAs()];

            if (method_exists($model, 'searchableProperties')) {
                $params['body']['mappings']['_doc']['properties'] = $model->searchableProperties();
            }

            $this->elastic->indices()->create($params);
        }

        $params = ['body' => []];

        foreach ($models as $model) {
            $params['body'][] = [
                'update' => [
                    '_id' => $model->getScoutKey(),
                    '_index' => $model->searchableAs(),
                    '_type' => '_doc',
                ]
            ];
            $params['body'][] = [
                'doc' => $model->toSearchableArray(),
                'doc_as_upsert' => true
            ];
        }

        $this->elastic->bulk($params);
    }

    /**
     * Remove the given model from the index.
     *
     * @param  Collection  $models
     * @return void
     */
    public function delete($models)
    {
        $params['body'] = [];

        foreach ($models as $model) {
            $params['body'][] = [
                'delete' => [
                    '_id' => $model->getScoutKey(),
                    '_index' => $model->searchableAs(),
                    '_type' => '_doc',
                ]
            ];
        }

        $this->elastic->bulk($params);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  Builder  $builder
     * @return mixed
     */
    public function search(Builder $builder)
    {
        return $this->performSearch($builder, [
            'filters' => $this->filters($builder),
            'size' => $builder->limit,
        ]);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  Builder  $builder
     * @param  int  $perPage
     * @param  int  $page
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        $result = $this->performSearch($builder, [
            'filters' => $this->filters($builder),
            'from' => ($page * $perPage) - $perPage,
            'size' => $perPage,
        ]);

       $result['nbPages'] = $result['total'] / $perPage;

        return $result;
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param  mixed  $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        return $results['hits']->pluck('_id')->values();
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return Collection
     */
    public function map(Builder $builder, $results, $model)
    {
        if ($results['total'] === 0) {
            return collect([]);
        }

        $ids = $results['hits']->pluck('_id');
        $models = $model->getScoutModelsByIds($builder, $ids->toArray())->keyBy($model->getKeyName());

        return $ids->map(function ($id) use ($models) {
            return $models[$id] ?? null;
        })->filter();
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param  mixed  $results
     * @return int
     */
    public function getTotalCount($results)
    {
        return $results['total'];
    }

    /**
     * Flush all of the model's records from the engine.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function flush($model)
    {
        try {
            $this->elastic->indices()->delete(['index' => $model->searchableAs()]);
        } catch (\Exception $exception) {
            //
        }
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  Builder  $builder
     * @param  array  $options
     * @return mixed
     */
    protected function performSearch(Builder $builder, array $options = [])
    {
        $searchableFields = collect($builder->model->searchableProperties())->filter(function ($field) {
            return $field['type'] === 'text' || $field['type'] === 'text';
        })->keys()->toArray();

        $params = [
            'index' => $builder->index ?: $builder->model->searchableAs(),
            'type' => '_doc',
            'body' => $builder->query ? [
                'query' => [
                    'bool' => [
                        'should' => [
                            [
                                'multi_match' => [
                                    'query' => $builder->query,
                                    'type' => 'cross_fields',
                                    'fields' => $searchableFields,
                                ],
                            ],
                            [
                                'multi_match' => [
                                    'query' => $builder->query,
                                    'type' => 'phrase_prefix',
                                    'fields' => $searchableFields,
                                ],
                            ],
                        ],
                        'minimum_should_match' => 1,
                    ]
                ],
            ] : [],
        ];

        if ($sort = $this->sort($builder)) {
            $params['body']['sort'] = $sort;
        }

        if (isset($options['from'])) {
            $params['body']['from'] = $options['from'];
        }

        if ($options['size']) {
            $params['body']['size'] = $options['size'];
        }

        if ($options['filters']) {
            $params['body']['query']['bool']['filter'] = $options['filters'];
        }

        if ($builder->callback) {
            return call_user_func($builder->callback, $this->elastic, $params);
        }

        $result = $this->elastic->search($params);
        $result['total'] = $result['hits']['total'];
        $result['hits'] = collect($result['hits']['hits'])->map(function ($hit) {
            $data = $hit['_source'];
            $data['_id'] = $hit['_id'];
            return $data;
        });

        return $result;
    }

    /**
     * Get the filter array for the query.
     *
     * @param  Builder  $builder
     * @return array
     */
    protected function filters(Builder $builder)
    {
        $operators = [
            '>' => 'gt',
            '>=' => 'gte',
            '<' => 'lt',
            '<=' => 'lte',
        ];

        return collect($builder->wheres)->map(function ($value, $key) use ($operators) {
            if (!is_array($value)) {
                return ['term' => [$key => $value]];
            }

            [$field, $operator, $value] = $value;
            $elasticOperator = $operators[$operator] ?? null;

            return $elasticOperator ? ['range' => [$field => [$elasticOperator => $value]]] : null;
        })->filter()->values()->all();
    }

    /**
     * Generates the sort if theres any.
     *
     * @param  Builder $builder
     * @return array|null
     */
    protected function sort($builder)
    {
        if (!count($builder->orders)) {
            return null;
        }

        return collect($builder->orders)->map(function($order) {
            return [$order['column'] => $order['direction']];
        })->toArray();
    }
}
