<?php

declare(strict_types=1);

namespace App\Engine\Game;

/**
 * Étapes d'un tour. Untap et Cleanup n'ouvrent jamais de ronde de priorité ; toutes les autres, si.
 * DeclareAttackers / DeclareBlockers / FirstStrikeDamage / CombatDamage ne se jouent que s'il y a des attaquants (voir CombatManager::skips).
 */
enum Phase: string
{
    case Untap = 'untap';
    case Upkeep = 'upkeep';
    case Draw = 'draw';
    case Main1 = 'main1';
    case BeginCombat = 'begin_combat';
    case DeclareAttackers = 'declare_attackers';
    case DeclareBlockers = 'declare_blockers';
    case FirstStrikeDamage = 'first_strike_damage'; // seulement si une créature au combat a l'initiative ou la double initiative (CR 510.4)
    case CombatDamage = 'combat_damage';
    case EndCombat = 'end_combat';
    case Main2 = 'main2';
    case End = 'end';
    case Cleanup = 'cleanup';

    public function next(): ?self
    {
        return match ($this) {
            self::Untap => self::Upkeep,
            self::Upkeep => self::Draw,
            self::Draw => self::Main1,
            self::Main1 => self::BeginCombat,
            self::BeginCombat => self::DeclareAttackers,
            self::DeclareAttackers => self::DeclareBlockers,
            self::DeclareBlockers => self::FirstStrikeDamage,
            self::FirstStrikeDamage => self::CombatDamage,
            self::CombatDamage => self::EndCombat,
            self::EndCombat => self::Main2,
            self::Main2 => self::End,
            self::End => self::Cleanup,
            self::Cleanup => null,
        };
    }

    /** Les joueurs reçoivent-ils la priorité pendant cette étape ? */
    public function hasPriority(): bool
    {
        return $this !== self::Untap && $this !== self::Cleanup;
    }

    /** Phase principale : le timing « sorcery » y est permis. */
    public function isMain(): bool
    {
        return $this === self::Main1 || $this === self::Main2;
    }
}
