<?php

namespace Repas\Repas\Infrastructure\Http\Form;


use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

abstract class AbstractDepartmentType extends AbstractType implements DataMapperInterface
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add("name", TextType::class, [
                "label" => "Name",
                "required" => true,
            ])
            ->add("image", UrlType::class, [
                "label" => "Image",
                "required" => true,
                // Valeur par défaut de Symfony 7, rendue explicite (symfony/form 7.1 : elle devient `null` en 8.0)
                "default_protocol" => "http",
            ])
            ->setDataMapper($this);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            "data_class" => null,
            "empty_data" => null,
        ]);
    }
}
