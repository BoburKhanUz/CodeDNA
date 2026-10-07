<?php

declare(strict_types=1);

namespace App\Http\Requests\GitHub;

use Illuminate\Validation\Validator;

/**
 * Refuses any field a GitHub request does not define: repository owner or
 * name, installation, commit, URL or token are never accepted from a client.
 * Field names are not echoed back.
 */
trait OnlyFields
{
    /**
     * @return list<string>
     */
    abstract protected function allowedFields(): array;

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (array_diff(array_keys($this->all()), $this->allowedFields()) !== []) {
                    $validator->errors()->add('request', 'This request contains fields that are not accepted.');
                }
            },
        ];
    }
}
