<?php declare(strict_types=1);

namespace NoEmptySession\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;

class ConfigForm extends Form
{
    public function init(): void
    {
        $this
            ->add([
                'name' => 'noemptysession_truncate',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Empty the table of sessions', // @translate
                    'info' => 'All users will be logged out, except you. The forms opened by visitors will have to be submitted again.', // @translate
                ],
                'attributes' => [
                    'id' => 'noemptysession_truncate',
                ],
            ]);
    }
}
