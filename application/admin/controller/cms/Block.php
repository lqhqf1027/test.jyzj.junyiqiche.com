<?php

namespace app\admin\controller\cms;

use app\common\controller\Backend;

/**
 * 区块表
 *
 * @icon fa fa-circle-o
 */
class Block extends Backend
{

    /**
     * Block模型对象
     */
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\cms\Block;
        $this->view->assign("statusList", $this->model->getStatusList());
    }

    public function index()
    {
        $typeArr = \app\admin\model\cms\Block::distinct('type')->column('type');
        $this->view->assign('typeList', $typeArr);
        $this->assignconfig('typeList', $typeArr);
        return parent::index();
    }

    public function selectpage_type()
    {
        $list = [];
        $word = (array)$this->request->request("q_word/a");
        $field = $this->request->request('showField');
        $keyValue = $this->request->request('keyValue');
        if (!$keyValue) {
            if (array_filter($word)) {
                foreach ($word as $k => $v) {
                    $list[] = ['id' => $v, $field => $v];
                }
            }
            $typeArr = \app\admin\model\cms\Block::column('type');
            $typeArr = array_unique($typeArr);
            foreach ($typeArr as $index => $item) {
                $list[] = ['id' => $item, $field => $item];
            }
        } else {
            $list[] = ['id' => $keyValue, $field => $keyValue];
        }
        return json(['total' => count($list), 'list' => $list]);
    }

    public function import()
    {
        return parent::import();
    }

}
