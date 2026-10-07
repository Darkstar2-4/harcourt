<?php

namespace WPML\Collect\Support;

use Countable;
use ArrayAccess;
use Traversable;
use ArrayIterator;
use CachingIterator;
use JsonSerializable;
use IteratorAggregate;
use InvalidArgumentException;
use WPML\Collect\Support\Traits\Macroable;
use WPML\Collect\Contracts\Support\Jsonable;
use WPML\Collect\Contracts\Support\Arrayable;

class Collection implements ArrayAccess, Arrayable, Countable, IteratorAggregate, Jsonable, JsonSerializable
{
    use Macroable;

    protected $items = [];

    public function __construct($items = [])
    {
        $this->items = $this->getArrayableItems($items);
    }

    public static function make($items = [])
    {
        return new static($items);
    }

    public function all()
    {
        return $this->items;
    }

    public function avg($callback = null)
    {
        if ($count = $this->count()) {
            return $this->sum($callback) / $count;
        }
    }

    public function average($callback = null)
    {
        return $this->avg($callback);
    }

    public function median($key = null)
    {
        $count = $this->count();

        if ($count == 0) {
            return;
        }

        $values = with(isset($key) ? $this->pluck($key) : $this)
                    ->sort()->values();

        $middle = (int) ($count / 2);

        if ($count % 2) {
            return $values->get($middle);
        }

        return (new static([
            $values->get($middle - 1), $values->get($middle),
        ]))->average();
    }

    public function mode($key = null)
    {
        $count = $this->count();

        if ($count == 0) {
            return;
        }

        $collection = isset($key) ? $this->pluck($key) : $this;

        $counts = new self;

        $collection->each(function ($value) use ($counts) {
            $counts[$value] = isset($counts[$value]) ? $counts[$value] + 1 : 1;
        });

        $sorted = $counts->sort();

        $highestValue = $sorted->last();

        return $sorted->filter(function ($value) use ($highestValue) {
            return $value == $highestValue;
        })->sort()->keys()->all();
    }

    public function collapse()
    {
        return new static(Arr::collapse($this->items));
    }

    public function contains($key, $value = null)
    {
        if (func_num_args() == 2) {
            return $this->contains(function ($item) use ($key, $value) {
                return data_get($item, $key) == $value;
            });
        }

        if ($this->useAsCallable($key)) {
            return ! is_null($this->first($key));
        }

        return in_array($key, $this->items);
    }

    public function containsStrict($key, $value = null)
    {
        if (func_num_args() == 2) {
            return $this->contains(function ($item) use ($key, $value) {
                return data_get($item, $key) === $value;
            });
        }

        if ($this->useAsCallable($key)) {
            return ! is_null($this->first($key));
        }

        return in_array($key, $this->items, true);
    }

    public function diff($items)
    {
        return new static(array_diff($this->items, $this->getArrayableItems($items)));
    }

    public function diffKeys($items)
    {
        return new static(array_diff_key($this->items, $this->getArrayableItems($items)));
    }

    public function each(callable $callback)
    {
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key) === false) {
                break;
            }
        }

        return $this;
    }

    public function every($step, $offset = 0)
    {
        $new = [];

        $position = 0;

        foreach ($this->items as $item) {
            if ($position % $step === $offset) {
                $new[] = $item;
            }

            $position++;
        }

        return new static($new);
    }

    public function except($keys)
    {
        $keys = is_array($keys) ? $keys : func_get_args();

        return new static(Arr::except($this->items, $keys));
    }

    public function filter(?callable $callback = null)
    {
        if ($callback) {
            return new static(Arr::where($this->items, $callback));
        }

        return new static(array_filter($this->items));
    }

    public function where($key, $operator, $value = null)
    {
        if (func_num_args() == 2) {
            $value = $operator;

            $operator = '=';
        }

        return $this->filter($this->operatorForWhere($key, $operator, $value));
    }

    protected function operatorForWhere($key, $operator, $value)
    {
        return function ($item) use ($key, $operator, $value) {
            $retrieved = data_get($item, $key);

            switch ($operator) {
                default:
                case '=':
                case '==':  return $retrieved == $value;
                case '!=':
                case '<>':  return $retrieved != $value;
                case '<':   return $retrieved < $value;
                case '>':   return $retrieved > $value;
                case '<=':  return $retrieved <= $value;
                case '>=':  return $retrieved >= $value;
                case '===': return $retrieved === $value;
                case '!==': return $retrieved !== $value;
            }
        };
    }

    public function whereStrict($key, $value)
    {
        return $this->where($key, '===', $value);
    }

    public function whereIn($key, $values, $strict = false)
    {
        $values = $this->getArrayableItems($values);

        return $this->filter(function ($item) use ($key, $values, $strict) {
            return in_array(data_get($item, $key), $values, $strict);
        });
    }

    public function whereInStrict($key, $values)
    {
        return $this->whereIn($key, $values, true);
    }

    public function first(?callable $callback = null, $default = null)
    {
        return Arr::first($this->items, $callback, $default);
    }

    public function flatten($depth = INF)
    {
        return new static(Arr::flatten($this->items, $depth));
    }

    public function flip()
    {
        return new static(array_flip($this->items));
    }

    public function forget($keys)
    {
        foreach ((array) $keys as $key) {
            $this->offsetUnset($key);
        }

        return $this;
    }

    public function get($key, $default = null)
    {
        if ($this->offsetExists($key)) {
            return $this->items[$key];
        }

        return value($default);
    }

    public function groupBy($groupBy, $preserveKeys = false)
    {
        $groupBy = $this->valueRetriever($groupBy);

        $results = [];

        foreach ($this->items as $key => $value) {
            $groupKeys = $groupBy($value, $key);

            if (! is_array($groupKeys)) {
                $groupKeys = [$groupKeys];
            }

            foreach ($groupKeys as $groupKey) {
                if (! array_key_exists($groupKey, $results)) {
                    $results[$groupKey] = new static;
                }

                $results[$groupKey]->offsetSet($preserveKeys ? $key : null, $value);
            }
        }

        return new static($results);
    }

    public function keyBy($keyBy)
    {
        $keyBy = $this->valueRetriever($keyBy);

        $results = [];

        foreach ($this->items as $key => $item) {
            $resolvedKey = $keyBy($item, $key);

            if (is_object($resolvedKey)) {
                $resolvedKey = (string) $resolvedKey;
            }

            $results[$resolvedKey] = $item;
        }

        return new static($results);
    }

    public function has($key)
    {
        return $this->offsetExists($key);
    }

    public function implode($value, $glue = null)
    {
        $first = $this->first();

        if (is_array($first) || is_object($first)) {
            return implode($glue, $this->pluck($value)->all());
        }

        return implode($value, $this->items);
    }

    public function intersect($items)
    {
        return new static(array_intersect($this->items, $this->getArrayableItems($items)));
    }

    public function isEmpty()
    {
        return empty($this->items);
    }

    protected function useAsCallable($value)
    {
        return ! is_string($value) && is_callable($value);
    }

    public function keys()
    {
        return new static(array_keys($this->items));
    }

    public function last(?callable $callback = null, $default = null)
    {
        return Arr::last($this->items, $callback, $default);
    }

    public function pluck($value, $key = null)
    {
        return new static(Arr::pluck($this->items, $value, $key));
    }

    public function map(callable $callback)
    {
        $keys = array_keys($this->items);

        $items = array_map($callback, $this->items, $keys);

        return new static(array_combine($keys, $items));
    }

    public function mapWithKeys(callable $callback)
    {
        return $this->flatMap($callback);
    }

    public function flatMap(callable $callback)
    {
        return $this->map($callback)->collapse();
    }

    public function max($callback = null)
    {
        $callback = $this->valueRetriever($callback);

        return $this->reduce(function ($result, $item) use ($callback) {
            $value = $callback($item);

            return is_null($result) || $value > $result ? $value : $result;
        });
    }

    public function merge($items)
    {
        return new static(array_merge($this->items, $this->getArrayableItems($items)));
    }

    public function combine($values)
    {
        return new static(array_combine($this->all(), $this->getArrayableItems($values)));
    }

    public function union($items)
    {
        return new static($this->items + $this->getArrayableItems($items));
    }

    public function min($callback = null)
    {
        $callback = $this->valueRetriever($callback);

        return $this->reduce(function ($result, $item) use ($callback) {
            $value = $callback($item);

            return is_null($result) || $value < $result ? $value : $result;
        });
    }

    public function only($keys)
    {
        if (is_null($keys)) {
            return new static($this->items);
        }

        $keys = is_array($keys) ? $keys : func_get_args();

        return new static(Arr::only($this->items, $keys));
    }

    public function forPage($page, $perPage)
    {
        return $this->slice(($page - 1) * $perPage, $perPage);
    }

	public function partition($key, $operator = null, $value = null)
	{
		$partitions = [new static, new static];

		$callback = func_num_args() === 1
			? $this->valueRetriever($key)
			: $this->operatorForWhere(...func_get_args());

		foreach ($this->items as $key => $item) {
			$partitions[(int) ! $callback($item, $key)][$key] = $item;
		}

		return new static($partitions);
	}
    public function pipe(callable $callback)
    {
        return $callback($this);
    }

    public function pop()
    {
        return array_pop($this->items);
    }

    public function prepend($value, $key = null)
    {
        $this->items = Arr::prepend($this->items, $value, $key);

        return $this;
    }

    public function push($value)
    {
        $this->offsetSet(null, $value);

        return $this;
    }

    public function pull($key, $default = null)
    {
        return Arr::pull($this->items, $key, $default);
    }

    public function put($key, $value)
    {
        $this->offsetSet($key, $value);

        return $this;
    }

    public function random($amount = 1)
    {
        if ($amount > ($count = $this->count())) {
            throw new InvalidArgumentException("You requested {$amount} items, but there are only {$count} items in the collection");
        }

        $keys = array_rand($this->items, $amount);

        if ($amount == 1) {
            return $this->items[$keys];
        }

        return new static(array_intersect_key($this->items, array_flip($keys)));
    }

    public function reduce(callable $callback, $initial = null)
    {
        return array_reduce($this->items, $callback, $initial);
    }

    public function reject($callback)
    {
        if ($this->useAsCallable($callback)) {
            return $this->filter(function ($value, $key) use ($callback) {
                return ! $callback($value, $key);
            });
        }

        return $this->filter(function ($item) use ($callback) {
            return $item != $callback;
        });
    }

    public function reverse()
    {
        return new static(array_reverse($this->items, true));
    }

    public function search($value, $strict = false)
    {
        if (! $this->useAsCallable($value)) {
            return array_search($value, $this->items, $strict);
        }

        foreach ($this->items as $key => $item) {
            if (call_user_func($value, $item, $key)) {
                return $key;
            }
        }

        return false;
    }

    public function shift()
    {
        return array_shift($this->items);
    }

    public function shuffle($seed = null)
    {
        $items = $this->items;

        if (is_null($seed)) {
            shuffle($items);
        } else {
            srand($seed);

            usort($items, function () {
                return rand(-1, 1);
            });
        }

        return new static($items);
    }

    public function slice($offset, $length = null)
    {
        return new static(array_slice($this->items, $offset, $length, true));
    }

    public function split($numberOfGroups)
    {
        if ($this->isEmpty()) {
            return new static;
        }

        $groupSize = ceil($this->count() / $numberOfGroups);

        return $this->chunk($groupSize);
    }

    public function chunk($size)
    {
        $chunks = [];

        foreach (array_chunk($this->items, $size, true) as $chunk) {
            $chunks[] = new static($chunk);
        }

        return new static($chunks);
    }

    public function sort(?callable $callback = null)
    {
        $items = $this->items;

        $callback
            ? uasort($items, $callback)
            : asort($items);

        return new static($items);
    }

    public function sortBy($callback, $options = SORT_REGULAR, $descending = false)
    {
        $results = [];

        $callback = $this->valueRetriever($callback);

        foreach ($this->items as $key => $value) {
            $results[$key] = $callback($value, $key);
        }

        $descending ? arsort($results, $options)
                    : asort($results, $options);

        foreach (array_keys($results) as $key) {
            $results[$key] = $this->items[$key];
        }

        return new static($results);
    }

    public function sortByDesc($callback, $options = SORT_REGULAR)
    {
        return $this->sortBy($callback, $options, true);
    }

    public function splice($offset, $length = null, $replacement = [])
    {
        if (func_num_args() == 1) {
            return new static(array_splice($this->items, $offset));
        }

        return new static(array_splice($this->items, $offset, $length, $replacement));
    }

    public function sum($callback = null)
    {
        if (is_null($callback)) {
            return array_sum($this->items);
        }

        $callback = $this->valueRetriever($callback);

        return $this->reduce(function ($result, $item) use ($callback) {
            return $result + $callback($item);
        }, 0);
    }

    public function take($limit)
    {
        if ($limit < 0) {
            return $this->slice($limit, abs($limit));
        }

        return $this->slice(0, $limit);
    }

    public function transform(callable $callback)
    {
        $this->items = $this->map($callback)->all();

        return $this;
    }

    public function unique($key = null, $strict = false)
    {
        if (is_null($key)) {
            return new static(array_unique($this->items, SORT_REGULAR));
        }

        $key = $this->valueRetriever($key);

        $exists = [];

        return $this->reject(function ($item) use ($key, $strict, &$exists) {
            if (in_array($id = $key($item), $exists, $strict)) {
                return true;
            }

            $exists[] = $id;
        });
    }

    public function uniqueStrict($key = null)
    {
        return $this->unique($key, true);
    }

    public function values()
    {
        return new static(array_values($this->items));
    }

    protected function valueRetriever($value)
    {
        if ($this->useAsCallable($value)) {
            return $value;
        }

        return function ($item) use ($value) {
            return data_get($item, $value);
        };
    }

    public function zip($items)
    {
        $arrayableItems = array_map(function ($items) {
            return $this->getArrayableItems($items);
        }, func_get_args());

        $params = array_merge([function () {
            return new static(func_get_args());
        }, $this->items], $arrayableItems);

        return new static(call_user_func_array('array_map', $params));
    }

    public function toArray()
    {
        return array_map(function ($value) {
            return $value instanceof Arrayable ? $value->toArray() : $value;
        }, $this->items);
    }

	#[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return array_map(function ($value) {
            if ($value instanceof JsonSerializable) {
                return $value->jsonSerialize();
            } elseif ($value instanceof Jsonable) {
                return json_decode($value->toJson(), true);
            } elseif ($value instanceof Arrayable) {
                return $value->toArray();
            } else {
                return $value;
            }
        }, $this->items);
    }

    public function toJson($options = 0)
    {
        return json_encode($this->jsonSerialize(), $options);
    }

	#[\ReturnTypeWillChange]
    public function getIterator()
    {
        return new ArrayIterator($this->items);
    }

    public function getCachingIterator($flags = CachingIterator::CALL_TOSTRING)
    {
        return new CachingIterator($this->getIterator(), $flags);
    }

	#[\ReturnTypeWillChange]
    public function count()
    {
        return count($this->items);
    }

    public function toBase()
    {
        return new self($this);
    }

	#[\ReturnTypeWillChange]
    public function offsetExists($key)
    {
        return null !== $key && array_key_exists($key, $this->items);
    }

	#[\ReturnTypeWillChange]
    public function offsetGet($key)
    {
        return $this->items[$key];
    }

	#[\ReturnTypeWillChange]
    public function offsetSet($key, $value)
    {
        if (is_null($key)) {
            $this->items[] = $value;
        } else {
            $this->items[$key] = $value;
        }
    }

	#[\ReturnTypeWillChange]
    public function offsetUnset($key)
    {
        unset($this->items[$key]);
    }

    public function __toString()
    {
        return $this->toJson();
    }

    protected function getArrayableItems($items)
    {
        if (is_array($items)) {
            return $items;
        } elseif ($items instanceof self) {
            return $items->all();
        } elseif ($items instanceof Arrayable) {
            return $items->toArray();
        } elseif ($items instanceof Jsonable) {
            return json_decode($items->toJson(), true);
        } elseif ($items instanceof JsonSerializable) {
            return $items->jsonSerialize();
        } elseif ($items instanceof Traversable) {
            return iterator_to_array($items);
        }

        return (array) $items;
    }


	public function mapToDictionary(callable $callback)
	{
		$dictionary = [];

		foreach ($this->items as $key => $item) {
			$pair = $callback($item, $key);

			$key = key($pair);

			$value = reset($pair);

			if (! isset($dictionary[$key])) {
				$dictionary[$key] = [];
			}

			$dictionary[$key][] = $value;
		}

		return new static($dictionary);
	}

	public function mapToGroups(callable $callback)
	{
		$groups = $this->mapToDictionary($callback);

		return $groups->map([$this, 'make']);
	}

	public function prioritize( callable $condition ) {
		$nonPrioritized = $this->reject( $condition );

		return $this
			->filter( $condition )
			->toBase()
			->merge( $nonPrioritized );
	}

	public function assocToPair() {
		return $this->map( function ( $item, $key ) {
			return [ $key, $item ];
		} )->values();
	}

	public function pairToAssoc() {
		return $this->mapWithKeys( function ( $pair ) {
			return [ $pair[0] => $pair[1] ];
		} );
	}

	public function eachWithTimeout( callable $fn, $timeout ) {
		$endTime = time() + $timeout;

		return $this->reduce( function ( Collection $unProcessed, $item ) use ( $endTime, $fn ) {
			if ( time() > $endTime ) {
				$unProcessed->push( $item );
			} else {
				$fn( $item );
			}

			return $unProcessed;
		}, wpml_collect() );
	}

	public function isNotEmpty()
	{
		return  ! empty($this->items);
	}

	public function crossJoin(...$lists)
	{
		return new static(Arr::crossJoin(
			$this->items, ...array_map([$this, 'getArrayableItems'], $lists)
		));
	}
}
