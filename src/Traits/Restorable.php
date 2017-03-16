<?php

namespace Dq\Rest\Traits;

trait Restorable {

    public function restore($item) {
        if (!$this->isSoftDeletable()) {
            throw new \Exception('Cannot restore non soft deletable object');
        }

        if (isset($this->restoreRequest) && !empty($this->restoreRequest)) {
            app()->make($this->restoreRequest);
        }

        return \DB::transaction(function() use ($item) {
            $object = $this->getItem($item, true);
            $object->restore();

            return response()->json('ok');
        });
    }
}
