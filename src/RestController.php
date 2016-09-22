<?php namespace Dq\Rest;

use App\Http\Controllers\Controller;

class RestController extends Controller {

    protected $model = null;

    protected $items_per_page = 50;

    protected $sort = 'id';

    protected $order = 'desc';

    protected $only_data = false;

    protected $only_meta = false;

    // Override this field to include custom fields to each item in list
    // See laravel append attributes
    protected $append = [];

    public function index() {
        $this->retreiveListParams();

        $model = $this->getModel();

        if ($this->only_meta) {
            return $this->response();
        }

        $items = $this->getItems();

        return $this->response($items['items'], ['total' => $items['total']]);
    }

    public function show($id) {
        $object = $this->getItem($id);
        $object = $this->appendAttributes($object, $this->append);
        return response()->json($object);
    }

    public function store() {
        $model = $this->getModel();

        return \DB::transaction(function() use ($model) {
            $data = $this->getRequestData();

            $object = new $model();
            $object->fill($data);
            $object = $this->beforeSave($object);

            if ($object->save()) {
                $this->afterSave($object);

                // Can still have errors (from nested relations for example)
                if (!$object->getErrors()->isEmpty()) {
                    \DB::rollBack();
                    return response()->json($object->getErrors(), 406);
                }
                \DB::commit();
                return response()->json($object);
            }
            else {
                \DB::rollBack();
                return response()->json($object->getErrors(), 406);
            }
        });
    }

    public function update($id) {
        $model = $this->getModel();

        return \DB::transaction(function() use ($model, $id) {
            $data = $this->getRequestData();
            $object = $model::find($id);
            $object->fill($data);
            $object = $this->beforeSave($object);

            if ($object->save() || $object->isClean()) {
                $this->afterSave($object);

                // Can still have errors (from nested relations for example)
                if (!$object->getErrors()->isEmpty()) {
                    \DB::rollBack();
                    return response()->json($object->getErrors(), 406);
                }
                \DB::commit();

                return response()->json($object);
            }
            else {
                \DB::rollBack();
                return response()->json($object->getErrors(), 406);
            }
        });
    }

    public function destroy($id) {
        $model = $this->getModel();

        return \DB::transaction(function() use ($model, $id) {
            $data = $this->getRequestData();
            $object = $model::find($id);
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
    }

    // Override this method if you want custom logic on how to
    // retreive items from database (eg. apply custom ordering function
    // or custom pagination)
    // Should return array of items and total number of items
    protected function getItems() {
        $model = $this->getModel();
        $model = new $model();
        $result = $this->getFiltered()->orderBy($model->getTable().'.'.$this->sort, $this->order)->paginate($this->items_per_page);
        $this->appendAttributes($result->items(), $this->append);
        return [
            'items' => $result->items(),
            'total' => $result->total(),
        ];
    }

    // Override this method if you want custom logic of
    // retrieving single item
    protected function getItem($id) {
        $model = $this->getModel();
        return $model::find($id);
    }

    // Override this method to add filtering
    protected function getFiltered() {
        $model = $this->getModel();
        return new $model();
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

}
