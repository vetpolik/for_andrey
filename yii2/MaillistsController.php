<?php

namespace app\modules\mailing\controllers;

use app\extensions\components\deferred\Manager;
use app\extensions\controllers\BackendController;
use app\extensions\widgets\SearchForm;
use app\modules\mailing\forms\mail\MailForm;
use app\modules\mailing\forms\maillist\FilterForm;
use app\modules\mailing\forms\maillist\ListForm;
use app\modules\mailing\models\activerecord\MailInList;
use app\modules\mailing\models\activerecord\MailList;
use app\modules\mailing\models\activerecord\TagList;
use app\modules\mailing\models\ListsThroughSuppressions;
use app\modules\mailing\models\MailLists;
use app\modules\mailing\models\MailListsDelete;
use app\modules\mailing\models\Mails;
use app\modules\mailing\models\TagLists;
use yii\helpers\Url;
use yii\web\NotFoundHttpException;

class MaillistsController extends BackendController
{
    public $reportTitle = 'Contacts';

    public function actionIndex()
    {
        $this->setPageTitle($this->reportTitle, '');
        $this->breadcrumbs[] = ['label' => $this->reportTitle, 'url' => Url::current()];

        $fields = [
            'id'     => 'ID',
            'name'   => 'Name of the List',
            'mails'  => 'Contacts Count',
            'tagId'  => 'Tag',
            'status' => 'Status',
            ''       => 'Actions'
        ];

        $searchFields = [
            'id' => [
                'type'    => 'select',
                'label'   => 'Name of the List',
                'options' => ['class' => 'form-control'],
                'items'   => MailLists::getMyLists()
            ],
            'tagId' => [
                'type'    => 'select',
                'label'   => 'Tag',
                'options' => ['class' => 'form-control'],
                'items'   => ['' => 'All'] + TagLists::getTagLists()
            ],
        ];

        $searchForm = new SearchForm($searchFields);
        $searchForm->setButtonTitle('Search');

        $form  = new ListForm();
        $model = MailLists::getInstance();

        $model->sqlSearch = $searchForm->getAttributes();
        if (\Yii::$app->user->getIdentity()->isWebmaster()) {
            $model->sqlSearch['ownerId'] = \Yii::$app->user->ID;
        }

        $model->joins = [
            TagList::tableName() . ' as t' => [
                'type'   => 'LEFT JOIN',
                'on'     => 't.id = l.tagId',
                'params' => []
            ]
        ];

        $model->currentPageNumber = \Yii::$app->getRequest()->get('page', 1);
        $model->pageSize          = \Yii::$app->getRequest()->get('per-page', 100);
        $model->setSortingField(\Yii::$app->getRequest()->get('sorting', false));
        $model->setSortingDirection(\Yii::$app->getRequest()->get('direction', false));
        list($data, $pagination) = $model->getPaginationData();

        return $this->render('index', [
            'data'       => $data,
            'model'      => $model,
            'pagination' => $pagination,
            'form'       => $form,
            'searchForm' => $searchForm,
            'fields'     => $fields
        ]);
    }

    public function actionAdd()
    {
        $this->layout = '//ajax';
        $form         = new ListForm(['scenario' => 'add']);

        if (\Yii::$app->getRequest()->isPost) {
            $form->setAttributes(\Yii::$app->getRequest()->post('ListForm'));
            if ($form->validate()) {
                $params = $form->getValues();
                $params['status'] = MailLists::STATUS_READY_TO_SEND;
                MailLists::getInstance()->insert($params);
                \Yii::$app->getSession()->setFlash('message', 'New email list created');
            }
        }
        return $this->render('add', ['form' => $form]);
    }

    public function actionEdit()
    {
        $this->layout = '//ajax';
        $form = new ListForm(['scenario' => 'edit']);
        $model = MailLists::getInstance();

        if (\Yii::$app->getRequest()->isPost) {
            $form->setAttributes(\Yii::$app->getRequest()->post('ListForm'));
            if ($form->validate()) {
                $model->update($form->getValues());
                \Yii::$app->getSession()->setFlash('message', 'Action successfully completed');
            }
        } else {
            $listId = \Yii::$app->getRequest()->get('id', 0);
            $list   = $model->fetchRow($listId);
            if ($list) {
                $form->setAttributes($list->getAttributes());
            }
        }
        return $this->render('edit', ['form' => $form]);
    }

    public function actionDelete()
    {
        $this->layout = '//ajax';
        $id           = (int)\Yii::$app->getRequest()->get('id', 0);
        $attributes   = MailLists::getInstance()->fetchRow(['id' => $id], true);
        if (empty($attributes)) {
            throw new NotFoundHttpException('Item not found.');
        }

        $form             = new ListForm(['scenario' => 'delete']);
        $form->attributes = $attributes;

        if (\Yii::$app->getRequest()->isPost) {
            if (\Yii::$app->user->getIdentity()->isWebmaster() && $attributes['ownerId'] !== \Yii::$app->user->ID) {
                \Yii::$app->getSession()->setFlash('errorMessage', 'Sorry, you can\'t manage this Emails list.');
            } else {

                if ($form->validate()) {
                    if (MailLists::getInstance()->update(['status' => MailLists::STATUS_DELETED, 'id' => $id])) {
                        MailListsDelete::getInstance()->insert(['maillistId' => $id, 'type' => MailListsDelete::TYPE_DELETE]);
                        Manager::getInstance()->addTask('MailingMailsListDelete');
                        \Yii::$app->getSession()->setFlash('message', 'Action successfully completed');
                    } else {
                        \Yii::$app->getSession()->setFlash('errorMessage', 'Sorry, an error occurred.');
                    }
                }
            }
        }

        return $this->render('delete', ['form' => $form]);
    }

    public function actionDeleteall()
    {
        $this->layout = '//ajax';
        $id           = (int)\Yii::$app->getRequest()->get('id', 0);
        $attributes   = MailLists::getInstance()->fetchRow(['id' => $id], true);
        if (empty($attributes)) {
            throw new NotFoundHttpException('Item not found.');
        }

        $form             = new ListForm(['scenario' => 'delete']);
        $form->attributes = $attributes;

        if (\Yii::$app->getRequest()->isPost) {
            if (\Yii::$app->user->getIdentity()->isWebmaster() && $attributes['ownerId'] !== \Yii::$app->user->ID) {
                \Yii::$app->getSession()->setFlash('errorMessage', 'Sorry, you can\'t manage this Emails list.');
            } else {
                if ($form->validate()) {
                    if (MailLists::getInstance()->update(['status' => MailLists::STATUS_DELETED, 'id' => $id])) {
                        MailListsDelete::getInstance()->insert(['maillistId' => $id, 'type' => MailListsDelete::TYPE_DELETE_ALL]);
                        Manager::getInstance()->addTask('MailingMailsListDelete');
                        \Yii::$app->getSession()->setFlash('message', 'Email deleted from the list');
                    } else {
                        \Yii::$app->getSession()->setFlash('errorMessage', 'Sorry, an error occurred.');
                    }
                }
            }
        }

        return $this->render('deleteall', ['form' => $form]);
    }

    public function actionManage()
    {
        $this->setPageTitle($this->reportTitle, 'List');

        $this->breadcrumbs[] = ['label' => 'List', 'url' => false];
        $this->breadcrumbs[] = ['label' => $this->reportTitle, 'url' => Url::current()];

        $fields = [
            'mail'    => 'Mail',
            'lname'   => 'Last Name',
            'fname'   => 'First Name',
            'country' => 'Country',
            'state'   => 'State',
            'zip'     => 'Zip',
            'gender'  => 'Gender',
            'dob'     => 'Day Of Birth',
            'custom'  => 'Custom',
            ''        => 'Actions'
        ];

        $searchFields = [
            'mail'    => 'Email',
            'fname'   => 'First Name',
            'lname'   => 'Last Name',
            'country' => 'Country',
            'state'   => 'State',
            'zip'     => 'Zip',
            'gender'  => [
                'type'    => 'select',
                'label'   => 'Gender',
                'options' => ['class' => 'form-control'],
                'items'   => [Mails::GENDER_MALE => 'Male', Mails::GENDER_FEMALE => 'Female']
            ],
            'ageFrom' => [
                'type'    => 'select',
                'label'   => 'Age From',
                'options' => ['class' => 'form-control _selectFrom', 'data-validate' => '_1'],
                'items'   => Mails::getAgeRange()
            ],
            'ageTill' => [
                'type'    => 'select',
                'label'   => 'Age Till',
                'options' => ['class' => 'form-control _selectTill_1'],
                'items'   => Mails::getAgeRange()
            ]
        ];

        $multiselect = [
            'url'        => Url::toRoute('/mailing/mails/deletefromlist', true),
            'selectData' => [1 => 'Delete From List'],
            'modalTitle' => 'Delete From List'
        ];

        $searchForm = new SearchForm($searchFields);
        $searchForm->setButtonTitle('Show');

        $form  = new MailForm();
        $model = Mails::getInstance();

        $model->sqlSearch = $searchForm->getAttributes();
        if (\Yii::$app->user->getIdentity()->isWebmaster()) {
            $model->sqlSearch['ownerId'] = \Yii::$app->user->ID;
        }

        $model->currentPageNumber = \Yii::$app->getRequest()->get('page', 1);
        $model->pageSize          = \Yii::$app->getRequest()->get('per-page', 100);
        $model->setSortingField(\Yii::$app->getRequest()->get('sorting', false));
        $model->setSortingDirection(\Yii::$app->getRequest()->get('direction', false));
        list($data, $pagination) = $model->getPaginationData();

        return $this->render('manage', [
            'data'        => $data,
            'model'       => $model,
            'pagination'  => $pagination,
            'form'        => $form,
            'searchForm'  => $searchForm,
            'fields'      => $fields,
            'multiselect' => $multiselect
        ]);
    }

    public function actionFiltersuppression()
    {
        $this->layout = '//ajax';
        $form = new FilterForm();
        if (\Yii::$app->getRequest()->isPost) {
            $attributes = \Yii::$app->getRequest()->post('FilterForm');
            $form->setAttributes($attributes);
            if ($form->validate()) {
                ListsThroughSuppressions::getInstance()->insert($form->getValues());
                MailLists::getInstance()->update(['id' => $attributes['listId'], 'status' => MailLists::STATUS_FILTER_THROUGH_SUPPRESSION]);
                Manager::getInstance()->addTask('MailingListThroughSuppression', ['id' => $attributes['listId']]);
                \Yii::$app->getSession()->setFlash('message', 'Filter is start. Time of filtering is depends of Emails count.');
            }
        } else {
            $listId = \Yii::$app->getRequest()->get('id', 0);
            $form->setAttributes(['listId' => $listId]);
        }
        return $this->render('filtersuppression', ['form' => $form]);
    }

    public function actionJoin()
    {
        $this->layout = '//ajax';
        $form         = new ListForm(['scenario' => 'add']);

        if (\Yii::$app->getRequest()->isPost) {
            $form->setAttributes(\Yii::$app->getRequest()->post('ListForm'));
            if ($form->validate()) {
                if (!empty($form->tagId)) {
                    $params           = $form->getValues();
                    $params['status'] = MailLists::STATUS_READY_TO_SEND;
                    $params['tagId']  = 0;
                    $listId           = MailLists::getInstance()->insert($params);
                    \Yii::$app->db->createCommand('INSERT INTO `mailing_list_tags_cron` (`tagId`, `listId`) VALUES (' .$form->tagId . ',' . $listId . ')')->execute();
                    Manager::getInstance()->addTask('MailingListJoinByTag');
                    \Yii::$app->getSession()->setFlash('message', 'Create is start. Time of creating is depends of Emails count.');
                }
            }
        }
        return $this->render('join', ['form' => $form]);
    }
}
