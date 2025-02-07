<?php

namespace Repas\User\Infrastructure\Http\Form;


use Repas\User\Application\UpdateUserSettings\UpdateUserSettingsCommand;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UserSettingType extends AbstractType implements DataMapperInterface
{
    public function buildForm(FormBuilderInterface $builder, array $options)
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
        if (!$viewData instanceof User) {
            return;
        }

        $forms = iterator_to_array($forms);

        $forms['defaultServing']->setData($viewData->getDefaultServing());
    }

    public function mapFormsToData(\Traversable $forms, mixed &$viewData): void
    {
        /** @var User $user */
        $user = $viewData;

        $forms = iterator_to_array($forms);

        $actualPassword = $forms['actualPassword']->getData();
        $newPassword = $forms['newPassword']->getData();
        $newPasswordRepeat = $forms['newPasswordRepeat']->getData();


        if ($newPassword || $newPasswordRepeat) {
            if (!$actualPassword || !$user->passwordMatch($actualPassword)) {
                //Comment controller que l'ancien mot de passe est bon.
                $forms['actualPassword']->addError(new FormError('Mot de passe actuel invalide'));
            }
            if ($newPassword !== $newPasswordRepeat) {
                $forms['newPasswordRepeat']->addError(new FormError("Les mots de passe ne correspondent pas"));
            }
        } else {
            $newPassword = null;
        }

        $viewData = new UpdateUserSettingsCommand(
            $user->getId(),
            $newPassword,
            $forms['defaultServing']->getData(),
        );
    }
}
