<?php

declare(strict_types=1);

namespace App\Modules\Exams\Bank;

/**
 * Starter banks so a school can try CBT exams and live quizzes straight away
 * (ADR 0042). Every question here was written for this platform: none is a
 * past JAMB, WAEC or NECO question, and the bank descriptions say so. Schools
 * add their own questions, or import them, next to these.
 */
final class SampleBanks
{
    public const NOTE = 'Sample practice questions written for this platform, not past JAMB, WAEC or NECO questions. Check them, edit them and add your own.';

    /**
     * @return list<array{name: string, passages: array<string, array{0: string, 1: string}>,
     *     questions: list<array{0: string, 1: list<string>, 2: int, 3: string, 4: string, 5: int, 6?: string}>}>
     *     [prompt, options, correct index, explanation, topic, difficulty, passage key]
     */
    public static function all(): array
    {
        $extra = SampleSubjects::topUps();
        $out = [];
        foreach (self::core() as $bank) {
            $bank['questions'] = array_merge($bank['questions'], $extra[$bank['name']] ?? []);
            $out[] = $bank;
        }

        return array_merge($out, SampleSubjects::banks(), SampleTech::banks());
    }

    /**
     * @return list<array{name: string, passages: array<string, array{0: string, 1: string}>,
     *     questions: list<array{0: string, 1: list<string>, 2: int, 3: string, 4: string, 5: int, 6?: string}>}>
     */
    private static function core(): array
    {
        return [
            [
                'name' => 'Use of English (sample)',
                'passages' => [
                    'borehole' => ['Read the passage and answer the questions that follow',
                        "When the borehole in Mrs Adebayo's street stopped working, the residents met under the mango tree to decide what to do. "
                        . 'Some wanted to wait for the local government to send engineers; others argued that waiting had already cost them three dry weeks. '
                        . 'In the end Tunde, a young electrician who had just finished his apprenticeship, offered to inspect the pump himself. '
                        . 'He found that a single burnt-out fuse had stopped the whole system. Within an hour the water was flowing again, '
                        . 'and the residents agreed to set aside a small sum every month so that such repairs would never again depend on luck.'],
                ],
                'questions' => [
                    ['Why did the residents meet under the mango tree?', ["To celebrate Tunde's return", 'To decide what to do about the broken borehole',
                        'To choose a new street leader', 'To plant a mango tree'], 1,
                        'The first sentence says they met "to decide what to do" after the borehole stopped working.', 'Comprehension', 1, 'borehole'],
                    ['What had stopped the pump?', ['A burnt-out fuse', 'A dry well', 'A broken pipe', 'A missing engineer'], 0,
                        'Tunde "found that a single burnt-out fuse had stopped the whole system".', 'Comprehension', 1, 'borehole'],
                    ["The residents' decision at the end of the passage shows that they wanted to", ['punish the local government', 'pay Tunde a salary',
                        'be ready for future repairs', 'dig a new borehole'], 2,
                        'Saving a little every month means repairs "would never again depend on luck".', 'Comprehension', 2, 'borehole'],
                    ['Which word is closest in meaning to "inspect" as it is used in the passage?', ['repair', 'examine', 'replace', 'ignore'], 1,
                        'To inspect something is to look at it carefully: to examine it.', 'Vocabulary', 1, 'borehole'],
                    ['Choose the option nearest in meaning to the word in bold: The manager was **reluctant** to sign the contract.', ['eager', 'unwilling', 'quick', 'proud'], 1,
                        'Reluctant means unwilling or hesitant.', 'Synonyms', 1],
                    ['Choose the option opposite in meaning to the word in bold: The new rules are **lenient**.', ['strict', 'kind', 'mild', 'fair'], 0,
                        'Lenient means not strict, so its opposite is strict.', 'Antonyms', 2],
                    ['Neither the teacher nor the students ___ in the hall.', ['was', 'were', 'is', 'has been'], 1,
                        'With "neither … nor", the verb agrees with the nearer subject: "students", which is plural.', 'Grammar: concord', 2],
                    ['She has been living in Abuja ___ 2019.', ['for', 'since', 'from', 'at'], 1,
                        '"Since" goes with a point in time (2019); "for" goes with a length of time (five years).', 'Grammar: prepositions', 1],
                    ['Choose the correctly spelt word.', ['accomodation', 'accommodation', 'acommodation', 'accommodatoin'], 1, 'Accommodation has a double c and a double m.', 'Spelling', 1],
                    ['If I ___ you, I would apologise.', ['am', 'was', 'were', 'be'], 2, 'In an unreal condition, standard English uses "were" for every person: "If I were you".',
                        'Grammar: conditionals', 2],
                    ['The plural of "criterion" is', ['criterions', 'criterias', 'criteria', 'criterion'], 2, '"Criterion" comes from Greek; its plural is "criteria".', 'Grammar: plurals', 2],
                    ['To "let the cat out of the bag" means to', ['lose a pet', 'reveal a secret', 'start a fight', 'waste time'], 1,
                        'The idiom means to give away a secret, usually by accident.', 'Idioms', 1],
                ],
            ],
            [
                'name' => 'Mathematics (sample)',
                'passages' => [],
                'questions' => [
                    ['Simplify 3/4 + 2/3.', ['5/7', '17/12', '5/12', '1/2'], 1, '3/4 + 2/3 = 9/12 + 8/12 = 17/12 (that is, 1 5/12).', 'Fractions', 1],
                    ['Solve for x: 2x − 5 = 11.', ['3', '6', '8', '16'], 2, '2x = 16, so x = 8.', 'Linear equations', 1],
                    ['Find 15% of 240.', ['24', '36', '30', '40'], 1, '15/100 × 240 = 36.', 'Percentages', 1],
                    ['Find the area of a circle of radius 7 cm. (Take π = 22/7.)', ['44 cm²', '154 cm²', '49 cm²', '308 cm²'], 1, 'Area = πr² = 22/7 × 7 × 7 = 154 cm².', 'Mensuration', 1],
                    ['Simplify 2³ × 2⁴.', ['2¹²', '2⁷', '4⁷', '2¹'], 1, 'When multiplying powers of the same base, add the indices: 3 + 4 = 7.', 'Indices', 1],
                    ['Find the mean of 4, 7, 9, 10 and 15.', ['7', '9', '9.5', '10'], 1, 'The sum is 45, and 45 ÷ 5 = 9.', 'Statistics', 1],
                    ['Factorise x² − 9.', ['(x − 9)(x + 1)', '(x − 3)(x + 3)', '(x − 3)²', '(x + 9)(x − 1)'], 1, 'x² − 9 is a difference of two squares: (x − 3)(x + 3).', 'Algebra', 2],
                    ['Evaluate log₁₀ 1000.', ['2', '3', '10', '100'], 1, '10³ = 1000, so log₁₀ 1000 = 3.', 'Logarithms', 1],
                    ['A trader buys an item for ₦2,000 and sells it for ₦2,500. Find the percentage profit.', ['20%', '25%', '50%', '5%'], 1,
                        'Profit = ₦500; 500 ÷ 2,000 × 100 = 25%.', 'Percentages', 2],
                    ['Find the sum of the interior angles of a hexagon.', ['540°', '720°', '900°', '1080°'], 1, '(n − 2) × 180° with n = 6 gives 4 × 180° = 720°.', 'Geometry', 2],
                    ['y varies directly as x, and y = 12 when x = 4. Find y when x = 10.', ['24', '30', '40', '48'], 1, 'y = kx with k = 12 ÷ 4 = 3, so y = 3 × 10 = 30.', 'Variation', 2],
                    ['A fair die is thrown once. What is the probability of getting an even number?', ['1/6', '1/3', '1/2', '2/3'], 2,
                        'Three of the six faces (2, 4, 6) are even: 3/6 = 1/2.', 'Probability', 1],
                    ['Convert 1011 in base two to base ten.', ['9', '10', '11', '13'], 2, '1×8 + 0×4 + 1×2 + 1×1 = 11.', 'Number bases', 2],
                ],
            ],
            [
                'name' => 'Physics (sample)',
                'passages' => [],
                'questions' => [
                    ['What is the SI unit of force?', ['joule', 'watt', 'newton', 'pascal'], 2, 'Force is measured in newtons (N); 1 N = 1 kg m/s².', 'Units', 1],
                    ['A car starts from rest and reaches 20 m/s in 5 s with uniform acceleration. Find its acceleration.', ['2 m/s²', '4 m/s²', '5 m/s²', '100 m/s²'], 1,
                        'a = (v − u) ÷ t = (20 − 0) ÷ 5 = 4 m/s².', 'Motion', 1],
                    ['A 12 V supply is connected across a 4 Ω resistor. What current flows?', ['0.33 A', '3 A', '16 A', '48 A'], 1, 'I = V ÷ R = 12 ÷ 4 = 3 A.', 'Current electricity', 1],
                    ['Which of these is a vector quantity?', ['mass', 'speed', 'displacement', 'energy'], 2, 'Displacement has size and direction; the others have size only.',
                        'Scalars and vectors', 1],
                    ['How much work is done in lifting a 10 kg load through 2 m? (g = 10 m/s²)', ['20 J', '100 J', '200 J', '2000 J'], 2, 'Work = mgh = 10 × 10 × 2 = 200 J.', 'Work and energy', 1],
                    ['The speed of light in a vacuum is about', ['3.0 × 10⁶ m/s', '3.0 × 10⁸ m/s', '3.4 × 10² m/s', '1.5 × 10¹¹ m/s'], 1,
                        'Light travels at about 3.0 × 10⁸ m/s; 3.4 × 10² m/s is roughly the speed of sound in air.', 'Waves', 1],
                    ['A wave has a frequency of 50 Hz and a wavelength of 2 m. Find its speed.', ['25 m/s', '52 m/s', '100 m/s', '48 m/s'], 2, 'v = fλ = 50 × 2 = 100 m/s.', 'Waves', 1],
                    ['By which process does heat travel through a vacuum?', ['conduction', 'convection', 'radiation', 'evaporation'], 2,
                        'Conduction and convection need a medium; radiation does not.', 'Heat', 1],
                    ['Find the kinetic energy of a 2 kg mass moving at 3 m/s.', ['6 J', '9 J', '18 J', '3 J'], 1, 'KE = ½mv² = ½ × 2 × 3² = 9 J.', 'Work and energy', 2],
                    ['A device uses 600 J of energy in 30 s. What is its power?', ['18000 W', '20 W', '630 W', '570 W'], 1, 'Power = energy ÷ time = 600 ÷ 30 = 20 W.', 'Work and energy', 1],
                ],
            ],
            [
                'name' => 'Chemistry (sample)',
                'passages' => [],
                'questions' => [
                    ['The atomic number of an element is the number of', ['neutrons in its nucleus', 'protons in its nucleus', 'protons and neutrons together', 'electron shells'],
                        1, 'Atomic number = number of protons; mass number = protons + neutrons.', 'Atomic structure', 1],
                    ['What is the pH of a neutral solution at 25 °C?', ['0', '1', '7', '14'], 2, 'Pure water and neutral solutions have a pH of 7 at 25 °C.', 'Acids and bases', 1],
                    ['What is the chemical formula of common salt?', ['NaCl', 'KCl', 'NaOH', 'Na₂CO₃'], 0, 'Common salt is sodium chloride, NaCl.', 'Formulae', 1],
                    ['Which gas turns limewater milky?', ['oxygen', 'hydrogen', 'carbon dioxide', 'nitrogen'], 2,
                        'Carbon dioxide reacts with calcium hydroxide to form insoluble calcium carbonate.', 'Gases', 1],
                    ['Which of these is an alkali metal?', ['calcium', 'aluminium', 'sodium', 'iron'], 2,
                        'Sodium is in Group 1, the alkali metals; calcium is an alkaline earth metal.', 'Periodic table', 1],
                    ['How many moles are there in 22 g of carbon(IV) oxide, CO₂? (C = 12, O = 16)', ['0.5', '1', '2', '22'], 0,
                        'Molar mass of CO₂ = 12 + 2 × 16 = 44 g/mol; 22 ÷ 44 = 0.5 mol.', 'The mole', 2],
                    ['What kind of bonding holds the atoms in a water molecule together?', ['ionic', 'covalent', 'metallic', 'hydrogen bonding only'], 1,
                        'Hydrogen and oxygen share electron pairs: covalent bonds. Hydrogen bonds act between water molecules.', 'Chemical bonding', 2],
                    ['Which method best separates sand from water?', ['distillation', 'filtration', 'sublimation', 'chromatography'], 1,
                        'Sand does not dissolve, so it is caught by a filter while water passes through.', 'Separation techniques', 1],
                    ['For iron to rust it needs', ['water only', 'oxygen only', 'water and oxygen', 'carbon dioxide and nitrogen'], 2,
                        'Rusting needs both water and oxygen; take away either and iron does not rust.', 'Metals', 1],
                    ['What is the electron arrangement of an atom with 11 electrons?', ['2, 9', '2, 8, 1', '2, 1, 8', '8, 2, 1'], 1,
                        'The first shell holds 2, the second 8, and the last electron goes into the third shell.', 'Atomic structure', 1],
                ],
            ],
            [
                'name' => 'Biology (sample)',
                'passages' => [],
                'questions' => [
                    ['Which organelle releases most of the energy a cell needs?', ['nucleus', 'ribosome', 'mitochondrion', 'vacuole'], 2,
                        'Aerobic respiration in the mitochondria releases most of the cell\'s energy.', 'Cell biology', 1],
                    ['In green plants, photosynthesis takes place mainly in the', ['chloroplasts', 'roots', 'xylem vessels', 'cell walls'], 0,
                        'Chloroplasts contain chlorophyll, which traps light energy.', 'Plant nutrition', 1],
                    ['Which blood cells defend the body against infection?', ['red blood cells', 'white blood cells', 'platelets', 'plasma proteins only'], 1,
                        'White blood cells engulf germs and make antibodies.', 'Transport in animals', 1],
                    ['Malaria is caused by', ['a virus', 'Plasmodium', 'a bacterium', 'a fungus'], 1,
                        'Malaria is caused by the protozoan Plasmodium, carried by the female Anopheles mosquito.', 'Health and disease', 1],
                    ['Which enzyme in saliva begins the digestion of starch?', ['pepsin', 'lipase', 'amylase', 'trypsin'], 2,
                        'Salivary amylase (ptyalin) breaks starch down to maltose.', 'Digestion', 1],
                    ['What is the functional unit of the kidney?', ['neuron', 'nephron', 'alveolus', 'villus'], 1, 'Each kidney has about a million nephrons, which filter the blood.', 'Excretion', 1],
                    ['Two heterozygous tall plants (Tt × Tt) are crossed. What is the expected ratio of tall to short offspring?', ['1 : 1', '3 : 1', '1 : 2 : 1', 'all tall'], 1,
                        'The offspring are TT, Tt, Tt and tt: three tall to one short.', 'Genetics', 2],
                    ['In the food chain grass → grasshopper → frog → hawk, which organism is the producer?', ['grasshopper', 'frog', 'hawk', 'grass'], 3,
                        'Producers make their own food by photosynthesis; here that is the grass.', 'Ecology', 1],
                    ['In humans, gaseous exchange takes place in the', ['trachea', 'bronchi', 'alveoli', 'larynx'], 2,
                        'The thin, moist walls of the alveoli let oxygen and carbon dioxide diffuse.', 'Respiration', 1],
                    ['A lack of which vitamin causes scurvy?', ['vitamin A', 'vitamin B1', 'vitamin C', 'vitamin D'], 2,
                        'Scurvy (bleeding gums, slow healing) comes from too little vitamin C.', 'Nutrition', 1],
                ],
            ],
        ];
    }
}
