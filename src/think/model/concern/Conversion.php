<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2019 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
declare (strict_types = 1);

namespace think\model\concern;

use think\App;
use think\Collection;
use think\Exception;
use think\Model;
use think\model\Collection as ModelCollection;
use think\model\relation\OneToOne;

/**
 * 模型数据转换处理
 */
trait Conversion
{
    /**
     * 数据输出显示的属性
     * @var array
     */
    protected $visible = [];

    /**
     * 数据输出隐藏的属性
     * @var array
     */
    protected $hidden = [];

    /**
     * 数据输出需要追加的属性
     * @var array
     */
    protected $append = [];

    /**
     * 数据集对象名
     * @var string
     */
    protected $resultSetType;

    /**
     * 设置需要附加的输出属性
     * @access public
     * @param  array $append   属性列表
     * @param  bool  $override 是否覆盖
     * @return $this
     */
    public function append(array $append = [], bool $override = false)
    {
        $this->append = $override ? $append : array_merge($this->append, $append);

        return $this;
    }

    /**
     * 设置附加关联对象的属性
     * @access public
     * @param  string       $attr    关联属性
     * @param  string|array $append  追加属性名
     * @return $this
     * @throws Exception
     */
    public function appendRelationAttr(string $attr, array $append)
    {
        $relation = App::parseName($attr, 1, false);

        if (isset($this->relation[$relation])) {
            $model = $this->relation[$relation];
        } else {
            $model = $this->getRelationData($this->$relation());
        }

        if ($model instanceof Model) {
            foreach ($append as $key => $attr) {
                $key = is_numeric($key) ? $attr : $key;
                if (isset($this->data[$key])) {
                    throw new Exception('bind attr has exists:' . $key);
                } else {
                    $this->data[$key] = $model->$attr;
                }
            }
        }

        return $this;
    }

    /**
     * 设置需要隐藏的输出属性
     * @access public
     * @param  array $hidden   属性列表
     * @param  bool  $override 是否覆盖
     * @return $this
     */
    public function hidden(array $hidden = [], bool $override = false)
    {
        $this->hidden = $override ? $hidden : array_merge($this->hidden, $hidden);

        return $this;
    }

    /**
     * 设置需要输出的属性
     * @access public
     * @param  array $visible
     * @param  bool  $override 是否覆盖
     * @return $this
     */
    public function visible(array $visible = [], bool $override = false)
    {
        $this->visible = $override ? $visible : array_merge($this->visible, $visible);

        return $this;
    }

    /**
     * 转换当前模型对象为数组
     * @access public
     * @return array
     */
    public function toArray(): array
    {
        $item    = [];
        $visible = [];
        $hidden  = [];

        // 合并关联数据
        $data = array_merge($this->data, $this->relation);

        // 过滤属性
        if (!empty($this->visible)) {
            $array = $this->parseAttr($this->visible, $visible);
            $data  = array_intersect_key($data, array_flip($array));
        } elseif (!empty($this->hidden)) {
            $array = $this->parseAttr($this->hidden, $hidden, false);
            $data  = array_diff_key($data, array_flip($array));
        }

        foreach ($data as $key => $val) {
            $item[$key] = $this->getArrayData($key, $val, $visible, $hidden);
        }

        // 追加属性（必须定义获取器）
        foreach ($this->append as $key => $name) {
            $this->appendAttrToArray($item, $key, $name);
        }

        return $item;
    }

    protected function appendAttrToArray(array &$item, $key, string $name)
    {
        if (is_array($name)) {
            // 追加关联对象属性
            $relation = $this->getRelation($key);

            if (!$relation) {
                $relation = $this->getAttr($key);
                $relation->visible($name);
            }

            $item[$key] = $relation->append($name)->toArray();
        } elseif (strpos($name, '.')) {
            list($key, $attr) = explode('.', $name);
            // 追加关联对象属性
            $relation = $this->getRelation($key);

            if (!$relation) {
                $relation = $this->getAttr($key);
                $relation->visible([$attr]);
            }

            $item[$key] = $relation->append([$attr])->toArray();
        } else {
            $value       = $this->getAttr($name);
            $item[$name] = $value;

            $this->getBindAttr($name, $value, $item);
        }
    }

    protected function getBindAttr($name, $value, array &$item = [])
    {
        $relation = $this->isRelationAttr($name);
        if (!$relation) {
            return false;
        }

        $modelRelation = $this->$relation();

        if ($modelRelation instanceof OneToOne) {
            $bindAttr = $modelRelation->getBindAttr();

            if ($bindAttr) {
                unset($item[$name]);
            }

            foreach ($bindAttr as $key => $attr) {
                $key = is_numeric($key) ? $attr : $key;

                if (isset($item[$key])) {
                    throw new Exception('bind attr has exists:' . $key);
                }

                $item[$key] = $value ? $value->getAttr($attr) : null;
            }
        }
    }

    protected function getArrayData(string $key, $val, array $visible, array $hidden)
    {
        if ($val instanceof Model || $val instanceof ModelCollection) {
            // 关联模型对象
            if (isset($visible[$key])) {
                $val->visible($visible[$key]);
            } elseif (isset($hidden[$key])) {
                $val->hidden($hidden[$key]);
            }
            // 关联模型对象
            return $val->toArray();
        }

        // 模型属性
        return $this->getAttr($key);
    }

    /**
     * 转换当前模型对象为JSON字符串
     * @access public
     * @param  integer $options json参数
     * @return string
     */
    public function toJson(int $options = JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->toArray(), $options);
    }

    /**
     * 移除当前模型的关联属性
     * @access public
     * @return $this
     */
    public function removeRelation()
    {
        $this->relation = [];
        return $this;
    }

    public function __toString()
    {
        return $this->toJson();
    }

    // JsonSerializable
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    /**
     * 转换数据集为数据集对象
     * @access public
     * @param  array|Collection $collection 数据集
     * @param  string           $resultSetType 数据集类
     * @return Collection
     */
    public function toCollection(iterable $collection, string $resultSetType = null): Collection
    {
        $resultSetType = $resultSetType ?: $this->resultSetType;

        if ($resultSetType && false !== strpos($resultSetType, '\\')) {
            $collection = new $resultSetType($collection);
        } else {
            $collection = new ModelCollection($collection);
        }

        return $collection;
    }

    /**
     * 解析隐藏及显示属性
     * @access protected
     * @param  array $attrs  属性
     * @param  array $result 结果集
     * @param  bool  $visible
     * @return array
     */
    protected function parseAttr(array $attrs, array &$result, bool $visible = true): array
    {
        $array = [];

        foreach ($attrs as $key => $val) {
            if (is_array($val)) {
                if ($visible) {
                    $array[] = $key;
                }

                $result[$key] = $val;
            } elseif (strpos($val, '.')) {
                list($key, $name) = explode('.', $val);

                if ($visible) {
                    $array[] = $key;
                }

                $result[$key][] = $name;
            } else {
                $array[] = $val;
            }
        }

        return $array;
    }
}
