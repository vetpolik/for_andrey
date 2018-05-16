<?php

use \basic\baseController;
use \Gumlet\ImageResize;

class TaskController extends baseController
{
    const ROW_IN_LIST           = 3;
    public static $taskImageDir = 'task_image';
    private $viewDir            = 'task'; 
    
	function actionList()
	{	
	    $this->view->pageTitle = 'Tasks List';   
        $this->model           = new TaskModel();

        $condition = $this->getCondition(self::ROW_IN_LIST, $this->model->getTableFields());

        if (empty($condition['order'])) {
            $condition['order'] = 'id desc';
        }
        
        $tableFields = $this->model->getTableFields();
        unset($tableFields['id']);
        unset($tableFields['imageType']);

	    $this->view->render($this->viewDir . DS . 'tasklist', [
	        'tasks'       => $this->model->getAll($condition),
	        'cols'        => $tableFields,
	        'countRows'   => ceil($this->model->getCount($condition) / self::ROW_IN_LIST),
	        'sortingCols' => ['userName', 'email', 'status'],
	        'currentPage' => (isset($_GET['p']) && !empty($_GET['p'])) ? (int) $_GET['p'] : 0
	    ]);
	}
	
	function actionAdd()
	{	
	    $this->view->pageTitle = 'Add New Task';
 
        $errors = [];
	    $task   = [];
	    if (isset($_POST['Task'])) {
	        $task                  = $_POST['Task'];
            $this->model           = new TaskModel();
            $this->model->email    = $_POST['Task']['email'];
            $this->model->userName = $_POST['Task']['userName'];
            $this->model->text     = $_POST['Task']['text'];
            $this->model->status   = TaskModel::STATUS_IN_PROGRESS;
            
            $errors = $this->model->checkData();
            if (empty($errors)) {
                $this->model->save();
                header('Location: ' . (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']);
                die();
            }
	    }

	    $this->view->render($this->viewDir . DS . 'taskadd', [
	        'task'   => $task,
	        'errors' => $errors
	    ]);
	}
	
    function actionEdit()
	{	
        if (!isset($_SESSION['isAdmin'])) {
            header('Location: ' . (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']);
            die();
        }	    
	    
	    $this->view->pageTitle = 'Edit Task';
	    
	    $this->model = new TaskModel();
        $taskId      = (int) $_GET['id'];
        $success     = '';
        
	    if (isset($_POST['Task'])) {
	        $task                = $_POST['Task'];
	        $this->model->id     = $taskId;
            $this->model->text   = $_POST['Task']['text'];
            $this->model->status = isset($_POST['Task']['done']) ? TaskModel::STATUS_DONE : TaskModel::STATUS_IN_PROGRESS;
            $this->model->update();
            $success = 'Task Updated';
	    }

	    $this->view->render($this->viewDir . DS . 'taskedit', [
	        'task'    => $this->model->getById($taskId),
	        'success' => $success
	    ]);
	}
	
	function actionView()
	{
	    $this->view->pageTitle = 'Task Detail';
        
        $taskId      = (int) $_GET['id'];
        $this->model = new TaskModel();

        $error = '';
        if (isset($_FILES['taskimage'])) {
            $fileType = $_FILES['taskimage']['type'];
            $allowed = ['image/jpeg', 'image/gif', 'image/png'];
            if (!in_array($fileType, $allowed)) {
                $error = 'Only jpg, gif, and png files are allowed.';
            } else {
                $imgExt     = substr($_FILES['taskimage']['name'], strpos($_FILES['taskimage']['name'], '.') + 1);
                $uploadDir  = UPLOAD_DIR . DS . static::$taskImageDir . DS;
                $uploadFile = $uploadDir . $taskId . '.' . $imgExt;
                if (!move_uploaded_file($_FILES['taskimage']['tmp_name'], $uploadFile)) {
                    $error = 'Upload error.';
                } else {
                    include APP_DIR . DS . LIBS_DIR . DS . 'ImageResize.php';
                    $image = new ImageResize($uploadFile);
                    $image->resizeToBestFit(320, 240);
                    $image->save($uploadFile);
                    
                    $this->model->id        = $taskId;
                    $this->model->imageType = $imgExt;
                    $this->model->update();
                }
            }            
        }

        $task = $this->model->getById($taskId);
        $this->view->render($this->viewDir . DS . 'taskview', [
	        'task'      => $task,
	        'error'     => $error,
	        'showImage' => is_file(UPLOAD_DIR . DS . static::$taskImageDir . DS . $taskId . '.' . $task['imageType'])
	    ]);
 	}
}