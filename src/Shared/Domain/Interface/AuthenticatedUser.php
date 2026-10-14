<?php

namespace Repas\Shared\Domain\Interface;


/**
 * Utilisateur connecté tel que les contextes autres que `User` le voient : son identifiant et son nombre de personnes
 * habituel, rien d'autre (un contexte n'importe pas le domaine d'un autre, ADR 0001). Les controllers et les formulaires
 * l'obtiennent par `#[CurrentUser] AuthenticatedUser $user` ou `assert($this->getUser() instanceof AuthenticatedUser)`.
 */
interface AuthenticatedUser
{
    public function getId(): string;

    public function getDefaultServing(): int;

    /**
     * Vrai pour un administrateur : il crée des ingrédients communs (sans créateur) là où un utilisateur crée des ingrédients
     * personnalisés. Les handlers du contexte Repas ne chargent pas le modèle `User`, c'est le controller qui le transmet.
     */
    public function isAdmin(): bool;
}
