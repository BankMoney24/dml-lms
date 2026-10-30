<?php

declare(strict_types=1);

namespace App\Modules\Exams\StarterKit;

/**
 * Ready-to-host live quizzes (ADR 0043). Short questions with up to four short
 * answers, so they fit a projector and a phone. Written for this platform.
 *
 * Each question is [prompt, answers, right answer index, seconds, points].
 */
final class SampleQuizzes
{
    /** @return list<array{title: string, questions: list<array{0: string, 1: list<string>, 2: int, 3: int, 4: string}>}> */
    public static function all(): array
    {
        return [
            ['title' => 'Friday general knowledge (sample)', 'questions' => [
                ['In which year did Nigeria become independent?', ['1960', '1963', '1970', '1999'], 0, 20, 'standard'],
                ['What is the capital of Nigeria?', ['Lagos', 'Abuja', 'Kano', 'Ibadan'], 1, 10, 'standard'],
                ['Which is the largest planet in our solar system?', ['Earth', 'Saturn', 'Jupiter', 'Mars'], 2, 20, 'standard'],
                ['How many continents are there?', ['5', '6', '7', '8'], 2, 10, 'standard'],
                ['Who wrote the novel "Things Fall Apart"?', ['Wole Soyinka', 'Chinua Achebe', 'Chimamanda Ngozi Adichie', 'Buchi Emecheta'], 1, 20, 'standard'],
                ['What is the chemical formula for water?', ['CO₂', 'H₂O', 'O₂', 'NaCl'], 1, 10, 'standard'],
                ['Which river meets the Benue at Lokoja?', ['Niger', 'Nile', 'Volta', 'Congo'], 0, 20, 'standard'],
                ['How many minutes are there in 3 hours?', ['120', '150', '180', '360'], 2, 20, 'double'],
            ]],
            ['title' => 'Science quick check (sample)', 'questions' => [
                ['Which gas do plants take in for photosynthesis?', ['oxygen', 'carbon dioxide', 'nitrogen', 'hydrogen'], 1, 20, 'standard'],
                ['Which organ pumps blood around the body?', ['lungs', 'liver', 'heart', 'kidney'], 2, 10, 'standard'],
                ['At what temperature does pure water freeze?', ['0 °C', '10 °C', '32 °C', '100 °C'], 0, 10, 'standard'],
                ['Which force pulls objects towards the Earth?', ['friction', 'magnetism', 'gravity', 'tension'], 2, 10, 'standard'],
                ['What is the centre of an atom called?', ['electron', 'nucleus', 'shell', 'orbit'], 1, 20, 'standard'],
                ['Sound cannot travel through', ['water', 'steel', 'air', 'a vacuum'], 3, 20, 'standard'],
                ['Sunlight helps the skin make which vitamin?', ['vitamin A', 'vitamin C', 'vitamin D', 'vitamin K'], 2, 20, 'standard'],
                ['What is the SI unit of energy?', ['newton', 'joule', 'watt', 'pascal'], 1, 20, 'double'],
            ]],
            ['title' => 'Mental maths sprint (sample)', 'questions' => [
                ['12 × 12 = ?', ['124', '132', '144', '154'], 2, 10, 'standard'],
                ['25% of 80 = ?', ['16', '20', '25', '40'], 1, 20, 'standard'],
                ['√81 = ?', ['7', '8', '9', '18'], 2, 10, 'standard'],
                ['7 × 8 = ?', ['48', '54', '56', '63'], 2, 10, 'standard'],
                ['1/2 + 1/4 = ?', ['2/6', '1/8', '2/4', '3/4'], 3, 20, 'standard'],
                ['1,000 − 357 = ?', ['643', '657', '743', '753'], 0, 20, 'standard'],
                ['2⁵ = ?', ['10', '25', '32', '64'], 2, 20, 'standard'],
                ['0.5 × 0.4 = ?', ['2.0', '0.2', '0.02', '0.9'], 1, 20, 'double'],
            ]],
            ['title' => 'Networking basics (sample)', 'questions' => [
                ['Which device connects different networks together?', ['switch', 'router', 'hub', 'cable'], 1, 20, 'standard'],
                ['How many bits are in an IPv4 address?', ['8', '16', '32', '128'], 2, 20, 'standard'],
                ['What does DNS do?', ['turns names into IP addresses', 'gives out IP addresses', 'blocks viruses', 'speeds up Wi-Fi'], 0, 20, 'standard'],
                ['Which of these addresses is private?', ['8.8.8.8', '192.168.0.5', '1.1.1.1', '41.58.0.1'], 1, 20, 'standard'],
                ['What does DHCP do?', ['turns names into addresses', 'gives devices IP addresses automatically', 'encrypts traffic', 'joins VLANs'], 1, 20, 'standard'],
                ['Which command checks whether a host replies?', ['ping', 'ls', 'cd', 'mkdir'], 0, 10, 'standard'],
                ['Which mask is the same as /24?', ['255.255.255.0', '255.255.0.0', '255.0.0.0', '255.255.255.255'], 0, 20, 'standard'],
                ['How many usable hosts does a /30 subnet have?', ['1', '2', '4', '6'], 1, 20, 'double'],
            ]],
            ['title' => 'Staying safe online (sample)', 'questions' => [
                ['Which password is strongest?', ['123456', 'password', 'mango-river-tiger-42', 'Ada2010'], 2, 20, 'standard'],
                ['A message says you won a prize and asks for your bank PIN. It is probably', ['a real prize', 'phishing', 'a bank update', 'a school notice'], 1, 20, 'standard'],
                ['Two-step sign-in asks for', ['your password twice', 'a second proof, such as a code', 'your home address', 'nothing extra'], 1, 20, 'standard'],
                ['Should you share your password with a close friend?', ['Yes, if you trust them', 'No, never', 'Only at weekends', 'Only by text message'], 1, 10, 'standard'],
                ['Before clicking a link in an email, you should', ['check where it really goes', 'click fast', 'forward it to everyone', 'reply with your PIN'], 0, 20, 'standard'],
                ['Why install software updates?', ['They fix security holes', 'They slow the phone down', 'They delete photos', 'There is no reason'], 0, 20, 'standard'],
                ['A padlock and https:// in the address mean', ['the connection is encrypted', 'the site is always honest', 'the site is free', 'there are no adverts'], 0, 20, 'standard'],
                ['Someone is bullying you online. A good first step is to', ['bully them back', 'tell a trusted adult and keep evidence', 'share their address',
                    'say nothing to anyone'], 1, 20, 'double'],
            ]],
            ['title' => 'Python warm-up (sample)', 'questions' => [
                ['print(2 + 3 * 4) shows', ['9', '14', '20', '24'], 1, 20, 'standard'],
                ['Which keyword starts a function?', ['def', 'func', 'fn', 'function'], 0, 10, 'standard'],
                ['len("hello") is', ['4', '5', '6', '"hello"'], 1, 20, 'standard'],
                ['10 // 3 is', ['1', '3', '3.33', '4'], 1, 20, 'standard'],
                ['Which of these is a list?', ['(1, 2)', '[1, 2]', '{1: 2}', '"1, 2"'], 1, 20, 'standard'],
                ['What does range(3) count?', ['1, 2, 3', '0, 1, 2', '0, 1, 2, 3', '3 only'], 1, 20, 'standard'],
                ['x = 5, then x += 2. What is x?', ['2', '5', '7', '52'], 2, 20, 'standard'],
                ['Which operator checks whether two values are equal?', ['=', '==', '=>', '!='], 1, 20, 'double'],
            ]],
        ];
    }
}
