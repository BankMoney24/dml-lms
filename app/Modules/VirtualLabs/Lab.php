<?php

declare(strict_types=1);

namespace App\Modules\VirtualLabs;

/**
 * A lab from the approved catalogue (LAB-008: teachers pick labs, they never
 * supply code that runs on the server). Each lab has a browser simulation
 * in public/assets/labs/<key>.js and this server-side half, which derives
 * the session's numbers from its seed and marks the answers.
 */
interface Lab
{
    public function definition(): LabDefinition;

    /**
     * The session's numbers. "public" goes to the browser; "secret" (an answer
     * key the simulation does not need) stays on the server.
     *
     * @return array{public: array<string, mixed>, secret: array<string, mixed>}
     */
    public function params(Seed $seed): array;

    /**
     * @param array<string, mixed> $public
     * @return list<LabField>
     */
    public function fields(array $public): array;

    /**
     * @param array{public: array<string, mixed>, secret: array<string, mixed>} $params
     * @param array<string, string>                                            $answers
     */
    public function check(array $params, array $answers): LabResult;
}
