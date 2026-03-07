<?php
/**
 * StreamCategoryController — редактирование категории стрима.
 */
class StreamCategoryController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        if (isset(RequestManager::getAll()['id'])) {
            $rCategoryArr = getCategory(RequestManager::getAll()['id']);
            if (!$rCategoryArr || !Authorization::check('adv', 'edit_cat')) {
                exit();
            }
        }

        $this->setTitle('Stream Category');
        $this->render('stream_category', compact('rCategoryArr'));
    }
}
