<?php namespace Dq\Rest;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\SoftDeletes;

class RestController extends Controller {

    protected $model = null;

    protected $items_per_page = 50;

    protected $sort = 'id';

    protected $order = 'desc';

    protected $only_data = false;

    protected $only_meta = false;

    protected $with = [];

    protected $allowedWith = [];

    protected $showTrashed = false;

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

        if ($this->only_meta) {
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

            \DB::commit();
            return response()->json($this->appendAttributes($object, $this->append));
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

            \DB::commit();
            return response()->json($this->appendAttributes($object, $this->append));
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

    // Index response should include data (array of items)
    // and meta (some other data, eg. relative tables, etc)
    protected function response($data = [], $metadata = []) {
        $meta = [];

        if (!$this->only_data) {
            $meta = $this->generateMetadata();
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
            $this->items_per_page = request('items_per_page');
        }

        if (request()->has('sort')) {
            $this->sort = request('sort');
        }

        if (request()->has('order')) {
            $this->order = request('order');
        }

        if (request()->has('only_meta')) {
            $this->only_meta = request('only_meta');
        }

        if (request()->has('only_data')) {
            $this->only_data = request('only_data');
        }

        if (request()->has('with')) {
            $this->with = explode(',', request('with'));
        }

        if (request()->has('show_trashed')) {
            $this->showTrashed = request('show_trashed') == 'true';
        }
    }

    // Override this method if you want custom logic on how to
    // retreive items from database (eg. apply custom ordering function
    // or custom pagination)
    // Should return array of items and total number of items
    protected function getItems() {
        $model = $this->getModel();
        $model = new $model();
        $result = $this->getFiltered()->with($this->getWith())->orderBy($model->getTable().'.'.$this->sort, $this->order)->paginate($this->items_per_page);
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

            return $builder->whereId($item)->first();
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
        return '\\App\\'.$this->model;
    }

    // Override this method if you want custom logic to append values to models
    // Basically does not have to be overriden at any time
    protected function appendAttributes($items, $attributes = []) {
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

    // Check that model is soft deletable
    protected function isSoftDeletable() {
        $traits = class_uses($this->getModel());
        return in_array(SoftDeletes::class, $traits);
    }

}
