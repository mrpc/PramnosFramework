<?php

namespace Pramnos\Support;

/**
 * Greek locale provider — extends FakerBaseProvider, overrides name methods,
 * and adds Greece-specific generators: addresses, phone numbers, ΑΦΜ, ΑΜΚΑ.
 *
 * Registered as the sole provider for the 'el_GR' locale; because it extends
 * FakerBaseProvider it also carries all generic methods (lorem, email, uuid…).
 *
 */
class FakerGrProvider extends FakerBaseProvider
{
    // =========================================================================
    // Data pools — Greek
    // =========================================================================

    /** @var list<string> */
    protected static array $maleFirstNames = [
        'Γιώργης', 'Νίκος', 'Κώστας', 'Γιάννης', 'Δημήτρης', 'Παναγιώτης',
        'Βασίλης', 'Χρήστος', 'Μιχάλης', 'Σταύρος', 'Αθανάσιος', 'Σπύρος',
        'Πέτρος', 'Ανδρέας', 'Αλέξης', 'Αντώνης', 'Θοδωρής', 'Ηλίας',
        'Στέφανος', 'Γρηγόρης', 'Κυριάκος', 'Σωτήρης', 'Λευτέρης',
        'Αποστόλης', 'Χαράλαμπος', 'Ευάγγελος', 'Φώτης', 'Μάρκος',
        'Οδυσσέας', 'Άρης', 'Γεράσιμος', 'Σπυρίδων', 'Κωνσταντίνος',
        'Θεοδόσης', 'Μιλτιάδης', 'Παναγής', 'Ζαχαρίας',
    ];

    /** @var list<string> */
    protected static array $femaleFirstNames = [
        'Μαρία', 'Ελένη', 'Αθηνά', 'Σοφία', 'Άννα', 'Κατερίνα', 'Γεωργία',
        'Χριστίνα', 'Νίκη', 'Δήμητρα', 'Αικατερίνη', 'Παναγιώτα', 'Βασιλική',
        'Σταυρούλα', 'Αντωνία', 'Ιωάννα', 'Θεοδώρα', 'Ελευθερία', 'Ευαγγελία',
        'Αγγελική', 'Φωτεινή', 'Ουρανία', 'Ζωή', 'Αλεξάνδρα', 'Μελίνα',
        'Μαρίνα', 'Αριάδνη', 'Σμαράγδα', 'Χαρά', 'Πολυξένη', 'Αρετή',
        'Καλλιόπη', 'Πηνελόπη', 'Βάσω', 'Κλαίρη',
    ];

    /** @var list<string> */
    protected static array $lastNames = [
        'Παπαδόπουλος', 'Παπαδημητρίου', 'Γεωργίου', 'Νικολάου', 'Χρηστίδης',
        'Κωνσταντίνου', 'Παπαδάκης', 'Αθανασίου', 'Σταυρόπουλος', 'Δημητρίου',
        'Νικολαΐδης', 'Ιωαννίδης', 'Αλεξίου', 'Γιαννόπουλος', 'Θεοδωρίδης',
        'Μαρκόπουλος', 'Σιδέρης', 'Φωτόπουλος', 'Καραγιάννης', 'Τσακίρης',
        'Βασιλόπουλος', 'Κυριακόπουλος', 'Παπαγεωργίου', 'Τριανταφύλλου',
        'Οικονόμου', 'Αναστασίου', 'Παναγιωτόπουλος', 'Χατζής', 'Ζαφειρόπουλος',
        'Τσιώλης', 'Βλάχος', 'Μανωλάς', 'Σπηλιόπουλος', 'Κολοκοτρώνης',
        'Στεφανίδης', 'Μαυρόπουλος', 'Ρούσσος', 'Ξενάκης', 'Δραγούμης',
        'Μπενάκης', 'Καλογεράκης', 'Πετράκης', 'Σαββάκης', 'Μανιάτης',
    ];

    /** @var list<string> */
    protected static array $cities = [
        'Αθήνα', 'Θεσσαλονίκη', 'Πάτρα', 'Ηράκλειο', 'Λάρισα', 'Βόλος',
        'Ιωάννινα', 'Τρίκαλα', 'Χαλκίδα', 'Σέρρες', 'Αλεξανδρούπολη',
        'Ξάνθη', 'Κομοτηνή', 'Καβάλα', 'Ρόδος', 'Κέρκυρα', 'Μυτιλήνη',
        'Χανιά', 'Ρέθυμνο', 'Σπάρτη', 'Κόρινθος', 'Λιβαδειά', 'Λαμία',
        'Αγρίνιο', 'Καλαμάτα', 'Τρίπολη', 'Ναύπλιο', 'Μεσολόγγι',
        'Αμαλιάδα', 'Πύργος', 'Κοζάνη', 'Βέροια', 'Νάουσα', 'Κιλκίς',
        'Φλώρινα', 'Γρεβενά', 'Δράμα', 'Καστοριά', 'Ηγουμενίτσα',
        'Λευκάδα', 'Ζάκυνθος', 'Κεφαλονιά', 'Σάμος', 'Χίος', 'Λήμνος',
    ];

    /** @var list<string> */
    protected static array $streetNames = [
        'Αθηνών', 'Θεσσαλονίκης', 'Πατησίων', 'Ερμού', 'Σταδίου',
        'Πανεπιστημίου', 'Αιόλου', 'Αλεξάνδρας', 'Κηφισίας', 'Συγγρού',
        'Βουλιαγμένης', 'Μεσογείων', 'Ακαδημίας', 'Σόλωνος', 'Ομήρου',
        'Σκουφά', 'Κολοκοτρώνη', 'Μητροπόλεως', 'Δημοκρατίας',
        'Ανεξαρτησίας', 'Εθνικής Αντιστάσεως', '28ης Οκτωβρίου',
        'Μακεδονίας', 'Ελευθερίου Βενιζέλου', 'Αγίου Δημητρίου',
        'Αρχιμήδους', 'Σωκράτους', 'Πλάτωνος', 'Αριστοτέλους',
        'Παπαδιαμάντη', 'Κανάρη', 'Αγίου Νικολάου',
    ];

    /** @var list<string> */
    protected static array $streetTypes = ['Οδός', 'Λεωφόρος', 'Πλατεία', 'Οδός'];

    /** @var list<string> */
    protected static array $regions = [
        'Αττική', 'Κεντρική Μακεδονία', 'Θεσσαλία', 'Δυτική Ελλάδα',
        'Πελοπόννησος', 'Κρήτη', 'Ανατολική Μακεδονία και Θράκη', 'Ήπειρος',
        'Στερεά Ελλάδα', 'Δυτική Μακεδονία', 'Βόρειο Αιγαίο', 'Νότιο Αιγαίο',
        'Ιόνια Νησιά',
    ];

    // =========================================================================
    // Names — override BaseProvider with Greek data
    // =========================================================================

    public function firstName(?string $gender = null): string
    {
        $gender = $gender ?? (mt_rand(0, 1) ? 'male' : 'female');
        return $gender === 'male'
            ? static::_randomElement(static::$maleFirstNames)
            : static::_randomElement(static::$femaleFirstNames);
    }

    public function lastName(): string
    {
        return static::_randomElement(static::$lastNames);
    }

    public function name(?string $gender = null): string
    {
        return $this->generator->firstName($gender) . ' ' . $this->generator->lastName();
    }

    // =========================================================================
    // Address
    // =========================================================================

    public function city(): string
    {
        return static::_randomElement(static::$cities);
    }

    public function streetName(): string
    {
        return static::_randomElement(static::$streetTypes) . ' '
             . static::_randomElement(static::$streetNames);
    }

    public function streetAddress(): string
    {
        return $this->generator->streetName() . ' ' . mt_rand(1, 250);
    }

    public function postcode(): string
    {
        // Greek postcodes: 5 digits, first digit 1–8
        return (string) mt_rand(1, 8) . str_pad((string) mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    public function address(): string
    {
        return $this->generator->streetAddress()
             . ', ' . $this->generator->postcode()
             . ' ' . $this->generator->city();
    }

    public function region(): string
    {
        return static::_randomElement(static::$regions);
    }

    // =========================================================================
    // Phone numbers
    // =========================================================================

    public function phoneNumber(): string
    {
        // Greek landlines: prefix + remaining digits = 10 total
        $prefixes = ['210', '2310', '2610', '2410', '2431', '2651', '2821', '2510'];
        $prefix   = static::_randomElement($prefixes);
        $digits   = 10 - strlen($prefix);
        return $prefix . static::_numerify(str_repeat('#', $digits));
    }

    public function mobileNumber(): string
    {
        // Greek mobiles: 69X + 7 digits = 10 total
        return '69' . mt_rand(0, 9) . static::_numerify('#######');
    }

    // =========================================================================
    // Greek tax / social identifiers
    // =========================================================================

    /**
     * Random 9-digit ΑΦΜ (Αριθμός Φορολογικού Μητρώου).
     * Check digit is not computed — valid format for test data only.
     */
    public function vatNumber(): string
    {
        return (string) static::_numberBetween(100_000_000, 999_999_999);
    }

    /**
     * Random 11-digit ΑΜΚΑ (Αριθμός Μητρώου Κοινωνικής Ασφάλισης).
     * Approximate DDMMYYXXXXX format — check digit not validated.
     */
    public function amka(): string
    {
        $day   = str_pad((string) mt_rand(1, 28), 2, '0', STR_PAD_LEFT);
        $month = str_pad((string) mt_rand(1, 12), 2, '0', STR_PAD_LEFT);
        $year  = str_pad((string) mt_rand(40, 99), 2, '0', STR_PAD_LEFT);
        return $day . $month . $year . static::_numerify('#####');
    }
}
