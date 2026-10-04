<?php

namespace Base\Video\Form;

use Base\Video\Entity\Playlist;
use Base\Video\Model\PlaylistDetails;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PlaylistDetailsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['label' => '@video.playlist.field.title'])
            ->add('description', TextareaType::class, ['label' => '@video.playlist.field.description', 'required' => false, 'attr' => ['rows' => 3]])
            ->add('visibility', ChoiceType::class, [
                'label' => '@video.playlist.field.visibility',
                'choices' => array_combine(array_map(fn ($v) => '@video.visibility.'.$v, Playlist::VISIBILITIES), Playlist::VISIBILITIES),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PlaylistDetails::class]);
    }
}
