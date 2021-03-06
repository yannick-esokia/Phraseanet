<?php

namespace Alchemy\Tests\Phrasea\Form\Login;

use Alchemy\Phrasea\Form\Login\PhraseaAuthenticationForm;
use Alchemy\Tests\Phrasea\Form\FormTestCase;

/**
 * @group functional
 * @group legacy
 */
class PhraseaAuthenticationFormTest extends FormTestCase
{
    protected function getForm()
    {
        return new PhraseaAuthenticationForm(self::$DI['app']);
    }
}
