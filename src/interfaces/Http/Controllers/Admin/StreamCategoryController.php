<?php
/**
 * StreamCategoryController — редактирование категории стрима (Phase 6.3 — Group A).
 */
class StreamCategoryController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        if (isset(CoreUtilities::$rRequest['id'])) {
            $rCategoryArr = getCategory(CoreUtilities::$rRequest['id']);
            if (!$rCategoryArr || !Authorization::check('adv', 'edit_cat')) {
                exit();
            }
        }

        $this->setTitle('Stream Category');
        $this->render('stream_category', compact('rCategoryArr'));
    }
}
