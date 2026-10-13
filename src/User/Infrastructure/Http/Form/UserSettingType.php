<?php

namespace Repas\User\Infrastructure\Http\Form;


use Repas\User\Application\UpdateUserSettings\UpdateUserSettingsCommand;
use Repas\User\Application\Query\GetCurrentUser\CurrentUserDTO;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UserSettingType extends AbstractType implements DataMapperInterface
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {

        $builder
            ->add('actualPassword', PasswordType::class, [
                'required' => false,
                'label' => 'Mot de passe actuel',
            ])
            ->add('newPassword', PasswordType::class, [
                'required' => false,
                'label' => 'Nouveau mot de passe',
            ])
            // Le mot de passe en clair, non mappé
            ->add('newPasswordRepeat', PasswordType::class, [
                'required' => false,
                'label' => 'Confirmation du nouveaux mot de passe',
            ])
            ->add('defaultServing', IntegerType::class, [
                'required' => true,
                'label' => 'Vous êtes combien à manger habituellement ?',
            ])
            ->setDataMapper($this);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'empty_data' => null,
        ]);
    }

    public function mapDataToForms(mixed $viewData, \Traversable $forms): void
    {
        if (!$viewData instanceof CurrentUserDTO) {
            return;
        }

        $forms = iterator_to_array($forms);

        $forms['defaultServing']->setData($viewData->defaultServing);
    }

    /**
     * @param CurrentUserDTO $viewData en entrée, remplacé par la commande de modification
     * @param-out UpdateUserSettingsCommand $viewData
     */
    public function mapFormsToData(\Traversable $forms, mixed &$viewData): void
    {
        $userId = $viewData->id;
        $forms = iterator_to_array($forms);
        $actualPassword = $forms['actualPassword']->getData();
        $newPassword = $forms['newPassword']->getData();
        $newPasswordRepeat = $forms['newPasswordRepeat']->getData();

        // Le mot de passe actuel est vérifié par le cas d'usage (UpdateUserSettingsHandler)
        if ($newPassword || $newPasswordRepeat) {
            if ($newPassword !== $newPasswordRepeat) {
                $forms['newPasswordRepeat']->addError(new FormError("Les mots de passe ne correspondent pas"));
            }
        } else {
            $newPassword = null;
        }

        $viewData = new UpdateUserSettingsCommand(
            $userId,
            $newPassword,
            $forms['defaultServing']->getData(),
            $actualPassword,
        );
    }
}
