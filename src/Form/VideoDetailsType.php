<?php

namespace Base\Video\Form;

use Base\Video\Entity\Video;
use Base\Video\Model\VideoDetails;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\LanguageType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** The studio's form for one film (on Model\VideoDetails). */
class VideoDetailsType extends AbstractType
{
    /** @param list<string> $categories @param list<string> $licenses */
    public function __construct(
        #[Autowire('%video.categories%')] private readonly array $categories = [],
        #[Autowire('%video.licenses%')] private readonly array $licenses = [],
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['label' => '@video.studio.field.title', 'attr' => ['maxlength' => 150]])
            ->add('description', TextareaType::class, ['label' => '@video.studio.field.description', 'required' => false, 'attr' => ['rows' => 6, 'maxlength' => 5000]])
            ->add('visibility', ChoiceType::class, [
                'label' => '@video.studio.field.visibility',
                'expanded' => true,
                'choices' => array_combine(array_map(fn ($v) => '@video.visibility.'.$v, Video::VISIBILITIES), Video::VISIBILITIES),
            ])
            ->add('publishAt', DateTimeType::class, ['label' => '@video.studio.field.publish_at', 'required' => false, 'widget' => 'single_text', 'input' => 'datetime'])
            ->add('category', ChoiceType::class, [
                'label' => '@video.studio.field.category',
                'required' => false,
                'placeholder' => '@video.studio.field.category_none',
                'choices' => array_combine(array_map(fn ($c) => '@video.category.'.$c, $this->categories), $this->categories),
            ])
            ->add('tags', TextType::class, ['label' => '@video.studio.field.tags', 'required' => false, 'help' => '@video.studio.field.tags_help'])
            ->add('language', LanguageType::class, ['label' => '@video.studio.field.language', 'required' => false, 'preferred_choices' => ['fr', 'en', 'de', 'es', 'it'], 'placeholder' => '@video.studio.field.language_none'])
            ->add('license', ChoiceType::class, [
                'label' => '@video.studio.field.license',
                'choices' => array_combine(array_map(fn ($l) => '@video.license.'.str_replace('-', '_', $l), $this->licenses), $this->licenses),
            ])
            ->add('commentsEnabled', CheckboxType::class, ['label' => '@video.studio.field.comments', 'required' => false])
            ->add('poster', HiddenType::class, ['required' => false])
            ->add('posterFile', FileType::class, ['label' => '@video.studio.field.poster_file', 'required' => false, 'attr' => ['accept' => 'image/jpeg,image/png,image/webp']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => VideoDetails::class]);
    }
}
