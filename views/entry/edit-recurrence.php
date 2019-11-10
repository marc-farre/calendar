<?php

use humhub\modules\calendar\interfaces\recurrence\RecurrenceFormWidget;

/* @var $form \humhub\widgets\ActiveForm */
/* @var $calendarEntryForm \humhub\modules\calendar\models\forms\CalendarEntryForm */
/* @var $contentContainer \humhub\modules\content\components\ContentContainerActiveRecord */
?>

<div class="modal-body">

    <?= RecurrenceFormWidget::widget([
            'form' => $form,
            'model' => $calendarEntryForm->entry,
            'picker' => '#calendarentryform-start_date'
    ])?>

</div>