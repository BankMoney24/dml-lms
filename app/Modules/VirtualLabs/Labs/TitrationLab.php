<?php

declare(strict_types=1);

namespace App\Modules\VirtualLabs\Labs;

use App\Modules\VirtualLabs\Answers;
use App\Modules\VirtualLabs\Lab;
use App\Modules\VirtualLabs\LabDefinition;
use App\Modules\VirtualLabs\LabField;
use App\Modules\VirtualLabs\LabResult;
use App\Modules\VirtualLabs\Seed;

/**
 * Chemistry, SS2: titrate hydrochloric acid of unknown concentration with
 * 0.100 mol/dm³ sodium hydroxide using phenolphthalein, then calculate the
 * acid's concentration. HCl + NaOH → NaCl + H₂O (1 : 1).
 */
final class TitrationLab implements Lab
{
    private const ACID_VOLUME = 25.0;
    private const BASE_CONCENTRATION = 0.100;

    public function definition(): LabDefinition
    {
        return new LabDefinition(
            key: 'titration',
            version: 1,
            title: 'Titration: how strong is the acid?',
            subject: 'Chemistry',
            track: 'school',
            level: 'SS2',
            summary: 'Titrate an acid of unknown strength with sodium hydroxide and calculate its concentration.',
            objective: 'Carry out an acid–base titration accurately and use the mole ratio to find a concentration.',
            tasks: [
                'Run a rough titration to find roughly where the end point is.',
                'Repeat carefully, adding the last few cm³ one drop at a time, until two titres agree within 0.20 cm³.',
                'Calculate the concentration of the hydrochloric acid in mol/dm³.',
            ],
            prerequisites: 'Moles, concentration in mol/dm³, and the reaction of an acid with an alkali.',
            minutes: 35,
        );
    }

    public function params(Seed $seed): array
    {
        return [
            'public' => [
                'acid' => $seed->int(50, 180) / 1000,
                'acid_volume' => self::ACID_VOLUME,
                'base' => self::BASE_CONCENTRATION,
            ],
            'secret' => [],
        ];
    }

    public function fields(array $public): array
    {
        return [
            new LabField('titre', 'Average of your concordant titres', 'number', 'cm³', 'To 2 decimal places, as read from the burette.'),
            new LabField('concentration', 'Concentration of the hydrochloric acid', 'number', 'mol/dm³', 'To 3 significant figures.'),
            new LabField('titres', 'Your titres', 'hidden'),
        ];
    }

    public function check(array $params, array $answers): LabResult
    {
        $p = $params['public'];
        $acid = (float) $p['acid'];
        $endPoint = $acid * (float) $p['acid_volume'] / (float) $p['base'];
        $titre = Answers::number($answers['titre'] ?? null);
        $concentration = Answers::number($answers['concentration'] ?? null);
        $titres = self::titres($answers['titres'] ?? '');
        $concordant = false;
        foreach ($titres as $i => $a) {
            foreach (array_slice($titres, $i + 1) as $b) {
                $concordant = $concordant || (abs($a - $b) <= 0.20 && abs($a - $endPoint) <= 0.30);
            }
        }

        return LabResult::fromParts([
            [
                'label' => 'Titre',
                'ok' => $titre !== null && abs($titre - $endPoint) <= 0.15,
                'detail' => sprintf('The end point was at %s cm³ of sodium hydroxide.', Answers::show($endPoint, 2)),
                'marks' => 30,
            ],
            [
                'label' => 'Concentration',
                'ok' => Answers::close($concentration, $acid, 3),
                'detail' => sprintf(
                    'Moles of NaOH = %s × %s ÷ 1000 = %s mol. HCl reacts 1 : 1, so the acid is %s ÷ (%s ÷ 1000) = %s mol/dm³.',
                    Answers::show((float) $p['base'], 3),
                    Answers::show($endPoint, 2),
                    Answers::show((float) $p['base'] * $endPoint / 1000, 6),
                    Answers::show((float) $p['base'] * $endPoint / 1000, 6),
                    Answers::show((float) $p['acid_volume'], 1),
                    Answers::show($acid, 3),
                ),
                'marks' => 50,
            ],
            [
                'label' => 'Concordant titres',
                'ok' => $concordant,
                'detail' => $concordant
                    ? 'You recorded two titres that agree within 0.20 cm³.'
                    : 'Record at least two accurate titres that agree within 0.20 cm³ of each other.',
                'marks' => 20,
            ],
        ], ['titre' => $answers['titre'] ?? '', 'concentration' => $answers['concentration'] ?? '', 'titres' => $titres]);
    }

    /** @return list<float> at most 10 recorded titres */
    private static function titres(string $json): array
    {
        $data = json_decode($json, true);

        return array_values(array_map('floatval', array_filter(is_array($data) ? array_slice($data, 0, 10) : [], 'is_numeric')));
    }
}
