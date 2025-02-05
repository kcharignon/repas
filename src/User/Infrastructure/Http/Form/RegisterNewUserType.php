<?php

namespace Repas\User\Infrastructure\Http\Form;

use Repas\User\Application\RegisterNewUser\RegisterNewUserCommand;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class RegisterNewUserType extends AbstractType implements DataMapperInterface
{


    public function __construct(
        private readonly UserRepository $userRepository,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'required' => true,
                'label'    => 'Adresse email',
            ])
            ->add('plainPassword', PasswordType::class, [
                'required' => true,
                'label'    => 'Mot de passe',
            ])
            // Le mot de passe en clair, non mappé
            ->add('plainPasswordRepeat', PasswordType::class, [
                'mapped'   => false,
                'required' => true,
                'label'    => 'Mot de passe',
            ])
            ->add('defaultServing', IntegerType::class, [
                'required' => true,
                'label'    => 'Vous êtes combien à manger habituellement ?',
            ])
            ->setDataMapper($this);
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'empty_data' => null,
        ]);
    }


    public function mapDataToForms(mixed $viewData, \Traversable $forms): void
    {
        // Le formulaire n'est jamais pré-rempli
    }

    public function mapFormsToData(\Traversable $forms, mixed &$viewData): void
    {
        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        $plainPassword       = $forms['plainPassword']->getData();
        $plainPasswordRepeat = $forms['plainPasswordRepeat']->getData();
        $email               = $forms['email']->getData();

        try {
            if ($this->userRepository->findOneByEmail($email) instanceof User) {
                // L'utilisateur existe déjà
                $forms['email']->addError(new FormError('Cette adresse email est déjà utilisée.'));
                return;
            }
        } catch (UserException $e) {
        }

        if ($plainPassword !== $plainPasswordRepeat) {
            $forms['plainPasswordRepeat']->addError(new FormError('Les mots de passe ne correspondent pas.'));
            return;
        }

        $viewData = new RegisterNewUserCommand(
            email: $forms['email']->getData(),
            passwordPlainText: $plainPassword,
            defaultServing: $forms['defaultServing']->getData(),
        );
    }
}
