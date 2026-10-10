<?php

declare(strict_types=1);

namespace App\Engine\Ability;

use App\Engine\Action\ActivateAbility;
use App\Engine\Decision\IllegalActionException;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameState;
use App\Engine\Game\PlayerState;
use App\Engine\Game\StackItem;
use App\Engine\Mana\ManaPayment;

/**
 * Activation des capacités activées. Seul chemin d'activation (humain, IA) : une capacité de mana est résolue
 * immédiatement (règle 605 : ni pile, ni nouvelle ronde, la priorité reste au joueur) ; toute autre capacité
 * va sur la pile et remet les passes à zéro.
 */
final class AbilityManager
{
    /** Peut-on activer cette capacité maintenant (coût payable, source prête) ? Le timing est celui de l'instant (priorité). */
    public static function canActivate(GameState $s, PlayerState $p, CardInstance $card, ActivatedAbility $ability): bool
    {
        if ($ability->tap) {
            if ($card->tapped) {
                return false;
            }
            // Une créature qui s'engage pour son coût est soumise au mal d'invocation.
            if ($card->isCreature() && $card->enteredTurn >= $s->turn) {
                return false;
            }
        }
        if ($ability->manaCost !== null && ManaPayment::plan($p, $ability->manaCost) === null) {
            return false;
        }

        return true;
    }

    public static function activate(GameState $s, ActivateAbility $a): void
    {
        $p = $s->player($a->player());
        $card = $p->findOnBattlefield($a->cardId) ?? throw new IllegalActionException('Source de la capacité absente du champ de bataille');
        $ability = $card->abilities()[$a->abilityIndex] ?? throw new IllegalActionException('Capacité inconnue');
        if (!self::canActivate($s, $p, $card, $ability)) {
            throw new IllegalActionException('Capacité non activable');
        }

        if ($ability->isManaAbility) {
            if (!isset($ability->options[$a->option])) {
                throw new IllegalActionException('Alternative de mana inconnue');
            }
            $mana = $p->activateManaSource($card, $ability, $a->option);
            $s->emit('ManaAbilityActivated', [
                'player' => $a->player(),
                'card' => $card->toPublic(),
                'abilityIndex' => $a->abilityIndex,
                'ability' => $ability->toArray(),
                'option' => $a->option,
                'mana' => $mana,
                'tapped' => $ability->tap ? [$card->id] : [],
                'pool' => $p->pool->toArray(),
            ], verbose: true);

            return; // résolue sur-le-champ : la pile et les passes ne sont pas touchées
        }

        $tapped = [];
        if ($ability->manaCost !== null) {
            $plan = ManaPayment::plan($p, $ability->manaCost) ?? throw new IllegalActionException('Mana insuffisant pour la capacité');
            $tapped = $p->payWithPlan($plan)['tapped'];
        }
        if ($ability->tap) {
            $card->tapped = true;
            array_unshift($tapped, $card->id);
        }

        $item = new StackItem('s' . $s->nextStackId++, $card, $a->player(), $a->targets, 0, $ability, $a->abilityIndex);
        $s->stack[] = $item;
        $s->emit('AbilityActivated', [
            'player' => $a->player(),
            'stackId' => $item->id,
            'card' => $card->toPublic(),
            'abilityIndex' => $a->abilityIndex,
            'ability' => $ability->toArray(),
            'targets' => $a->targets,
            'tapped' => $tapped,
        ]);

        $s->priority->passed = [];
        $s->priority->announced = false; // l'activateur reçoit la priorité en premier
    }
}
