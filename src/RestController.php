<?php namespace Staskjs\Rest;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class RestController extends Controller {

    protected $model = null;

    protected $resource = null;

    protected $itemsPerPage = 50;

    protected $sort = 'id';

    protected $order = 'desc';

    protected $onlyData = false;

    protected $onlyMeta = false;

    private $with = [];

    protected $allowedWith = [];

    protected $fields = ['*'];

    protected $showTrashed = false;

    protected $getItemBy;

    // You can use form request here for each of the REST methods
    // Just assign class name:
    // protected $indexRequest = MyIndexRequest::class;
    // This makes local rules() method obsolete, because all validation goes inside form request
    // So you should move validation rules there
    protected $indexRequest;

    protected $showRequest;

    protected $storeRequest;

    protected $updateRequest;

    protected $destroyRequest;

    // Override this field to include custom fields to each item in list
    // See laravel append attributes
    protected $append = [];

    public function index() {
        if ($this->indexRequest) {
            app()->make($this->indexRequest);
        }

        $this->retreiveListParams();

        $model = $this->getModel();

        if ($this->onlyMeta) {
            return $this->response();
        }

        $items = $this->getItems();

        return $this->response($items['items'], ['total' => $items['total']]);
    }

    public function show($item) {
        if ($this->showRequest) {
            app()->make($this->showRequest);
        }

        $object = $this->getItem($item);
        $object = $this->appendAttributes($object, $this->append);
        if (!empty($this->resource)) {
            $resource = $this->resource;
            $object = new $resource($object);
        }
        return response()->json($object);
    }

    public function store() {
        if ($this->storeRequest) {
            app()->make($this->storeRequest);
        }

        $model = $this->getModel();

        return \DB::transaction(function() use ($model) {
            $data = $this->getRequestData();

            if (!$this->storeRequest) {
                $validator = \Validator::make($data, $this->rules());
                if ($validator->fails()) {
                    return response()->json($validator->errors(), 406);
                }
            }

            $object = new $model();
            $object->fill($data);
            $object = $this->beforeSave($object);

            $object->save();
            $this->afterSave($object);
            $object->refresh();
            $object = $this->getItem($object);

            \DB::commit();

            if (!empty($this->resource)) {
                $resource = $this->resource;
                $object = new $resource($object);
            }
            else {
                $object = $this->appendAttributes($object, $this->append);
            }
            return response()->json($object);
        });
    }

    public function update($item) {
        if ($this->updateRequest) {
            app()->make($this->updateRequest);
        }

        $model = $this->getModel();

        return \DB::transaction(function() use ($model, $item) {
            $data = $this->getRequestData();

            if (!$this->updateRequest) {
                $validator = \Validator::make($data, $this->rules());
                if ($validator->fails()) {
                    return response()->json($validator->errors(), 406);
                }
            }

            $object = $this->getItem($item);
            $object->fill($data);
            $object = $this->beforeSave($object);

            $object->save();
            $this->afterSave($object);
            $object->refresh();

            \DB::commit();

            if (!empty($this->resource)) {
                $resource = $this->resource;
                $object = new $resource($object);
            }
            else {
                $object = $this->appendAttributes($object, $this->append);
            }
            return response()->json($object);
        });
    }

    public function destroy($item) {
        if ($this->destroyRequest) {
            app()->make($this->destroyRequest);
        }

        $model = $this->getModel();

        return \DB::transaction(function() use ($model, $item) {
            $data = $this->getRequestData();
            $object = $this->getItem($item);
            $object->fill($data);
            $object->delete();

            return response()->json();
        });
    }

    public function getMetadata() {
        return response()->json($this->generateMetadata());
    }

    // Index response should include data (array of items)
    // and meta (some other data, eg. relative tables, etc)
    protected function response($data = [], $metadata = []) {
        $meta = [];

        if (!$this->onlyData) {
            $meta = $this->generateMetadata();
        }

        if (!empty($this->resource)) {
            $data = call_user_func([$this->resource, 'collection'], collect($data));
        }

        return response()->json(['data' => $data, 'meta' => $metadata + $meta]);
    }

    // Override this method if you want to return additional data (metadata)
    protected function generateMetadata() {
        return [];
    }

    // Override this method if you want to modify request params
    // For example, change filter values or add anything
    protected function getRequestData() {
        return request()->all();
    }

    // Retreive common values from params to controller fields to be easily accesible
    protected function retreiveListParams() {
        if (request()->has('items_per_page')) {
            $this->itemsPerPage = request('items_per_page');
        }

        if (request()->has('sort')) {
            $this->sort = request('sort');
        }

        if (request()->has('order')) {
            $this->order = request('order');
        }

        if (request()->has('only_meta')) {
            $this->onlyMeta = request('only_meta');
        }

        if (request()->has('only_data')) {
            $this->onlyData = request('only_data');
        }

        if (request()->has('with')) {
            $this->with = explode(',', str_replace(' ', '', request('with')));
        }

        if (request()->has('show_trashed')) {
            $this->showTrashed = request('show_trashed') == 'true';
        }

        if (request()->has('fields')) {
            $this->fields = explode(',', str_replace(' ', '', request('fields')));
        }
    }

    // Override this method if you want custom logic on how to
    // retreive items from database (eg. apply custom ordering function
    // or custom pagination)
    // Should return array of items and total number of items
    protected function getItems() {
        $itemsPerPage = $this->itemsPerPage == 'all' ? 999999999 : $this->itemsPerPage;
        $query = $this->getFiltered()
            ->select($this->fields)
            ->with($this->getWith());

        $result = $this->smartSort($query)->paginate($itemsPerPage);

        $this->appendAttributes($result->items(), $this->append);
        return [
            'items' => $result->items(),
            'total' => $result->total(),
        ];
    }

    // Override this method if you want custom logic of
    // retrieving single item
    protected function getItem($item, $withTrashed = false) {
        $this->retreiveListParams();

        if (is_object($item)) {
            $item->load($this->getWith());
            return $item;
        }
        else {
            $model = $this->getModel();
            $builder = $model::with($this->getWith());
            if ($this->isSoftDeletable() && $withTrashed) {
                $builder = $builder->withTrashed();
            }
            $primaryKey = !empty($this->getItemBy)
                ? $this->getItemBy
                : (new $model)->getKeyName();

            $res = $builder->where($primaryKey, $item)->first();
            if (!$res) {
                throw new ModelNotFoundException("{$model} with {$primaryKey} = {$item} was not found");
            }
        }
    }

    // Override this method to add filtering
    protected function getFiltered() {
        $model = $this->getModel();

        $model = new $model();

        if ($this->isSoftDeletable() && $this->showTrashed) {
            $model = $model->withTrashed();
        }

        return $model;
    }

    // Override this method if you want to modify model before saving it
    protected function beforeSave($model) {
        return $model;
    }

    // Override this method if you want to do something with model after it is saved
    protected function afterSave($model) {
        return $model;
    }

    // Override this method if your model is under custom namespace
    protected function getModel() {
        if (starts_with($this->model, 'App\\')) {
            return $this->model;
        }
        return '\\App\\' . $this->model;
    }

    // Override this method if you want to custom sort model
    protected function smartSort($query) {
        if ($this->sort) {
            return $query->orderBy($this->getTableName() . '.' . $this->sort, $this->order);
        }
        return $query;
    }

    // Override this method if you want custom logic to append values to models
    // Basically does not have to be overriden at any time
    protected function appendAttributes($items, $attributes = []) {
        $isSimpleArray = array_values($attributes) === $attributes;

        // If attribute array contains relations, then check that these relations were required
        if (!$isSimpleArray) {
            $attributes = collect($attributes)->filter(function($relation, $attribute) {
                return is_null($attribute) || $this->hasWith($relation) || is_null($relation);
            })->keys()->all();
        }

        if (is_array($items)) {
            foreach ($items as $item) {
                $item->append($attributes);
            }
        }
        else {
            $items->append($attributes);
        }
        return $items;
    }

    // Override this method to add validation rules
    protected function rules() {
        return [];
    }

    // Filter "with" parameter against "allowedWith"
    protected function getWith() {
        return collect($this->with)->filter(function ($item) {
            return in_array($item, $this->allowedWith);
        })->toArray();
    }

    // Check if relation was requested with "with"
    protected function hasWith($relation) {
        return in_array($relation, $this->with);
    }

    // Check that model is soft deletable
    protected function isSoftDeletable() {
        $traits = class_uses($this->getModel());
        return in_array(SoftDeletes::class, $traits);
    }

    // Get model table name
    protected function getTableName() {
        $model = $this->getModel();
        $model = new $model();

        return $model->getTable();
    }
}

