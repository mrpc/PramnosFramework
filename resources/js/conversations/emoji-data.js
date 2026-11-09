/**
 * The emoji the conversations offer: eight categories, each emoji with its English name (from
 * the Unicode names, so a search for "fire" finds 🔥), the ones offered before a person has used
 * any, and the search and the "used lately" an emoji picker or a `:` suggestion reads. No
 * library: plain data, an ES module, so an application's own chat can import it too.
 *
 * Tested with `node --test tests/js/conversations-logic.test.js`.
 */

/** @type {Array<{key: string, label: string, icon: string, emoji: Array<[string, string]>}>} */
export const CATEGORIES = [{"key":"smileys","label":"Emoji: smileys","icon":"😀","emoji":[["😀","grinning"],["😃","smiling with open mouth"],["😄","smiling with open mouth and smiling eyes"],["😁","grinning with smiling eyes"],["😆","smiling with open mouth and tightly-closed eyes"],["😅","smiling with open mouth and cold sweat"],["🤣","rolling on the floor laughing"],["😂","face with tears of joy"],["🙂","slightly smiling"],["🙃","upside-down"],["😉","winking"],["😊","smiling with smiling eyes"],["😇","smiling with halo"],["🥰","smiling with smiling eyes and three hearts"],["😍","smiling with heart-shaped eyes"],["🤩","grinning with star eyes"],["😘","face throwing a kiss"],["😗","kissing"],["😚","kissing with closed eyes"],["😙","kissing with smiling eyes"],["😋","face savouring delicious food"],["😛","face with stuck-out tongue"],["😜","face with stuck-out tongue and winking eye"],["🤪","grinning with one large and one small eye"],["😝","face with stuck-out tongue and tightly-closed eyes"],["🤑","money-mouth"],["🤗","hugging"],["🤭","smiling with smiling eyes and hand covering mouth"],["🤫","face with finger covering closed lips"],["🤔","thinking"],["🤐","zipper-mouth"],["🤨","face with one eyebrow raised"],["😐","neutral"],["😑","expressionless"],["😶","face without mouth"],["😏","smirking"],["😒","unamused"],["🙄","face with rolling eyes"],["😬","grimacing"],["🤥","lying"],["😌","relieved"],["😔","pensive"],["😪","sleepy"],["🤤","drooling"],["😴","sleeping"],["😷","face with medical mask"],["🤒","face with thermometer"],["🤕","face with head-bandage"],["🤢","nauseated"],["🤮","face with open mouth vomiting"],["🤧","sneezing"],["🥵","overheated"],["🥶","freezing"],["😵","dizzy"],["🤯","shocked with exploding head"],["🤠","face with cowboy hat"],["🥳","face with party horn and party hat"],["😎","smiling with sunglasses"],["🤓","nerd"],["🧐","face with monocle"],["😕","confused"],["😟","worried"],["🙁","slightly frowning"],["☹️","white frowning"],["😮","face with open mouth"],["😯","hushed"],["😲","astonished"],["😳","flushed"],["🥺","face with pleading eyes"],["😦","frowning with open mouth"],["😧","anguished"],["😨","fearful"],["😰","face with open mouth and cold sweat"],["😥","disappointed but relieved"],["😢","crying"],["😭","loudly crying"],["😱","face screaming in fear"],["😖","confounded"],["😣","persevering"],["😞","disappointed"],["😓","face with cold sweat"],["😩","weary"],["😫","tired"],["🥱","yawning"],["😤","face with look of triumph"],["😡","pouting"],["😠","angry"],["🤬","serious with symbols covering mouth"]]},{"key":"gestures","label":"Emoji: people","icon":"👋","emoji":[["👋","waving hand sign"],["🤚","raised back of hand"],["🖐️","raised hand with fingers splayed"],["✋","raised hand"],["🖖","raised hand with part between middle and ring fingers"],["👌","ok hand sign"],["🤏","pinching hand"],["✌️","victory hand"],["🤞","hand with index and middle fingers crossed"],["🤟","i love you hand sign"],["🤘","sign of the horns"],["🤙","call me hand"],["👈","white left pointing backhand index"],["👉","white right pointing backhand index"],["👆","white up pointing backhand index"],["🖕","reversed hand with middle finger extended"],["👇","white down pointing backhand index"],["☝️","white up pointing index"],["👍","thumbs up sign"],["👎","thumbs down sign"],["✊","raised fist"],["👊","fisted hand sign"],["🤛","left-facing fist"],["🤜","right-facing fist"],["👏","clapping hands sign"],["🙌","person raising both hands in celebration"],["👐","open hands sign"],["🤲","palms up together"],["🤝","handshake"],["🙏","person with folded hands"],["✍️","writing hand"],["💅","nail polish"],["🤳","selfie"],["💪","flexed biceps"],["🦾","mechanical arm"],["🦿","mechanical leg"],["🦵","leg"],["🦶","foot"],["👂","ear"],["🦻","ear with hearing aid"],["👃","nose"],["🧠","brain"],["🦷","tooth"],["🦴","bone"],["👀","eyes"],["👁️","eye"],["👅","tongue"],["👄","mouth"]]},{"key":"hearts","label":"Emoji: hearts","icon":"❤️","emoji":[["❤️","heavy black heart"],["🧡","orange heart"],["💛","yellow heart"],["💚","green heart"],["💙","blue heart"],["💜","purple heart"],["🖤","black heart"],["🤍","white heart"],["🤎","brown heart"],["💔","broken heart"],["❣️","heavy heart exclamation mark ornament"],["💕","two hearts"],["💞","revolving hearts"],["💓","beating heart"],["💗","growing heart"],["💖","sparkling heart"],["💘","heart with arrow"],["💝","heart with ribbon"],["💟","heart decoration"],["☮️","peace symbol"],["✝️","latin cross"],["☪️","star and crescent"],["🕉️","om symbol"],["☸️","wheel of dharma"],["✡️","star of david"],["🔯","six pointed star with middle dot"],["🕎","menorah with nine branches"],["☯️","yin yang"],["☦️","orthodox cross"],["🛐","place of worship"],["⛎","ophiuchus"],["♈","aries"],["♉","taurus"],["♊","gemini"],["♋","cancer"],["♌","leo"],["♍","virgo"],["♎","libra"],["♏","scorpius"],["♐","sagittarius"],["♑","capricorn"],["♒","aquarius"],["♓","pisces"],["🆔","squared id"],["⚛️","atom symbol"],["💟","heart decoration"],["💌","love letter"],["💋","kiss mark"]]},{"key":"animals","label":"Emoji: animals","icon":"🐶","emoji":[["🐶","dog"],["🐱","cat"],["🐭","mouse"],["🐹","hamster"],["🐰","rabbit"],["🦊","fox"],["🐻","bear"],["🐼","panda"],["🐨","koala"],["🐯","tiger"],["🦁","lion"],["🐮","cow"],["🐷","pig"],["🐽","pig nose"],["🐸","frog"],["🐵","monkey"],["🙈","see-no-evil monkey"],["🙉","hear-no-evil monkey"],["🙊","speak-no-evil monkey"],["🐒","monkey"],["🐔","chicken"],["🐧","penguin"],["🐦","bird"],["🐤","baby chick"],["🐣","hatching chick"],["🐥","front-facing baby chick"],["🦆","duck"],["🦅","eagle"],["🦉","owl"],["🦇","bat"],["🐺","wolf"],["🐗","boar"],["🐴","horse"],["🦄","unicorn"],["🐝","honeybee"],["🐛","bug"],["🦋","butterfly"],["🐌","snail"],["🐞","lady beetle"],["🐜","ant"],["🦟","mosquito"],["🦗","cricket"],["🕷️","spider"],["🕸️","spider web"],["🦂","scorpion"],["🐢","turtle"],["🐍","snake"],["🦎","lizard"],["🦖","t-rex"],["🦕","sauropod"],["🐙","octopus"],["🦑","squid"],["🦐","shrimp"],["🦞","lobster"],["🦀","crab"],["🐡","blowfish"],["🐠","tropical fish"],["🐟","fish"],["🐬","dolphin"],["🐳","spouting whale"],["🐋","whale"],["🦈","shark"],["🐊","crocodile"],["🐅","tiger"],["🐆","leopard"],["🦓","zebra"],["🦍","gorilla"],["🦧","orangutan"],["🐘","elephant"],["🦛","hippopotamus"],["🦏","rhinoceros"],["🐪","dromedary camel"],["🐫","bactrian camel"],["🦒","giraffe"],["🦘","kangaroo"],["🐃","water buffalo"],["🐂","ox"],["🐄","cow"],["🐎","horse"],["🐖","pig"]]},{"key":"food","label":"Emoji: food","icon":"🍇","emoji":[["🍇","grapes"],["🍈","melon"],["🍉","watermelon"],["🍊","tangerine"],["🍋","lemon"],["🍌","banana"],["🍍","pineapple"],["🥭","mango"],["🍎","red apple"],["🍏","green apple"],["🍐","pear"],["🍑","peach"],["🍒","cherries"],["🍓","strawberry"],["🥝","kiwifruit"],["🍅","tomato"],["🥥","coconut"],["🥑","avocado"],["🍆","aubergine"],["🥔","potato"],["🥕","carrot"],["🌽","ear of maize"],["🌶️","hot pepper"],["🥒","cucumber"],["🥬","leafy green"],["🥦","broccoli"],["🧄","garlic"],["🧅","onion"],["🍄","mushroom"],["🥜","peanuts"],["🌰","chestnut"],["🍞","bread"],["🥐","croissant"],["🥖","baguette bread"],["🥨","pretzel"],["🥯","bagel"],["🥞","pancakes"],["🧇","waffle"],["🧀","cheese wedge"],["🍖","meat on bone"],["🍗","poultry leg"],["🥩","cut of meat"],["🥓","bacon"],["🍔","hamburger"],["🍟","french fries"],["🍕","slice of pizza"],["🌭","hot dog"],["🥪","sandwich"],["🌮","taco"],["🌯","burrito"],["🥙","stuffed flatbread"],["🧆","falafel"],["🥚","egg"],["🍳","cooking"],["🥘","shallow pan of food"],["🍲","pot of food"],["🥣","bowl with spoon"],["🥗","green salad"],["🍿","popcorn"],["🧈","butter"],["🧂","salt shaker"],["🥫","canned food"],["🍱","bento box"],["🍘","rice cracker"],["🍙","rice ball"],["🍚","cooked rice"],["🍛","curry and rice"],["🍜","steaming bowl"],["🍝","spaghetti"],["🍠","roasted sweet potato"],["🍢","oden"],["🍣","sushi"],["🍤","fried shrimp"],["🍥","fish cake with swirl design"],["🥮","moon cake"],["🍡","dango"],["🥟","dumpling"],["🥠","fortune cookie"],["🥡","takeout box"],["🦀","crab"],["🍦","soft ice cream"],["🍧","shaved ice"],["🍨","ice cream"],["🍩","doughnut"],["🍪","cookie"],["🎂","birthday cake"],["🍰","shortcake"],["🧁","cupcake"],["🥧","pie"],["🍫","chocolate bar"],["🍬","candy"],["🍭","lollipop"],["🍮","custard"],["🍯","honey pot"],["🍼","baby bottle"],["🥛","glass of milk"],["☕","hot beverage"],["🍵","teacup without handle"],["🍶","sake bottle and cup"],["🍾","bottle with popping cork"],["🍷","wine glass"],["🍸","cocktail glass"],["🍹","tropical drink"],["🍺","beer mug"],["🍻","clinking beer mugs"],["🥂","clinking glasses"],["🥃","tumbler glass"],["🥤","cup with straw"],["🧃","beverage box"],["🧉","mate drink"],["🧊","ice cube"]]},{"key":"activities","label":"Emoji: activities","icon":"⚽","emoji":[["⚽","soccer ball"],["🏀","basketball and hoop"],["🏈","american football"],["⚾","baseball"],["🥎","softball"],["🎾","tennis racquet and ball"],["🏐","volleyball"],["🏉","rugby football"],["🥏","flying disc"],["🎱","billiards"],["🪀","yo-yo"],["🏓","table tennis paddle and ball"],["🏸","badminton racquet and shuttlecock"],["🏒","ice hockey stick and puck"],["🏑","field hockey stick and ball"],["🥍","lacrosse stick and ball"],["🏏","cricket bat and ball"],["🥅","goal net"],["⛳","flag in hole"],["🪁","kite"],["🏹","bow and arrow"],["🎣","fishing pole and fish"],["🤿","diving mask"],["🥊","boxing glove"],["🥋","martial arts uniform"],["🎽","running shirt with sash"],["🛹","skateboard"],["🛷","sled"],["⛸️","ice skate"],["🥌","curling stone"],["🎿","ski and ski boot"],["⛷️","skier"],["🏂","snowboarder"],["🪂","parachute"],["🏋️","weight lifter"],["🤼","wrestlers"],["🤸","person doing cartwheel"],["🤺","fencer"],["⛹️","person with ball"],["🤾","handball"],["🏌️","golfer"],["🏇","horse racing"],["🧘","person in lotus position"],["🏊","swimmer"],["🤽","water polo"],["🚣","rowboat"],["🧗","person climbing"],["🚴","bicyclist"],["🚵","mountain bicyclist"],["🎪","circus tent"],["🎭","performing arts"],["🎨","artist palette"],["🎬","clapper board"],["🎤","microphone"],["🎧","headphone"],["🎼","musical score"],["🎹","musical keyboard"],["🥁","drum with drumsticks"],["🎷","saxophone"],["🎺","trumpet"],["🎸","guitar"],["🪕","banjo"],["🎻","violin"],["🎲","game die"],["♟️","black chess pawn"],["🎯","direct hit"],["🎳","bowling"],["🎮","video game"],["🎰","slot machine"],["🧩","jigsaw puzzle piece"]]},{"key":"travel","label":"Emoji: travel","icon":"🚗","emoji":[["🚗","automobile"],["🚕","taxi"],["🚙","recreational vehicle"],["🚌","bus"],["🚎","trolleybus"],["🏎️","racing car"],["🚓","police car"],["🚑","ambulance"],["🚒","fire engine"],["🚐","minibus"],["🚚","delivery truck"],["🚛","articulated lorry"],["🚜","tractor"],["🦯","probing cane"],["🦽","manual wheelchair"],["🦼","motorized wheelchair"],["🛴","scooter"],["🚲","bicycle"],["🛵","motor scooter"],["🏍️","racing motorcycle"],["🛺","auto rickshaw"],["🚨","police cars revolving light"],["🚔","oncoming police car"],["🚍","oncoming bus"],["🚘","oncoming automobile"],["🚖","oncoming taxi"],["🚡","aerial tramway"],["🚠","mountain cableway"],["🚟","suspension railway"],["🚃","railway car"],["🚋","tram car"],["🚞","mountain railway"],["🚝","monorail"],["🚄","high-speed train"],["🚅","high-speed train with bullet nose"],["🚈","light rail"],["🚂","steam locomotive"],["🚆","train"],["🚇","metro"],["🚊","tram"],["🚉","station"],["✈️","airplane"],["🛫","airplane departure"],["🛬","airplane arriving"],["🛩️","small airplane"],["💺","seat"],["🛰️","satellite"],["🚀","rocket"],["🛸","flying saucer"],["🚁","helicopter"],["🛶","canoe"],["⛵","sailboat"],["🚤","speedboat"],["🛥️","motor boat"],["🛳️","passenger ship"],["⛴️","ferry"],["🚢","ship"],["⚓","anchor"],["⛽","fuel pump"],["🚧","construction sign"],["🚦","vertical traffic light"],["🚥","horizontal traffic light"],["🚏","bus stop"],["🗺️","world map"],["🗿","moyai"],["🗽","statue of liberty"],["🗼","tokyo tower"],["🏰","european castle"],["🏯","japanese castle"],["🏟️","stadium"],["🎡","ferris wheel"],["🎢","roller coaster"],["🎠","carousel horse"],["⛲","fountain"],["⛱️","umbrella on ground"],["🏖️","beach with umbrella"],["🏝️","desert island"],["🏜️","desert"],["🌋","volcano"],["⛰️","mountain"],["🏔️","snow capped mountain"],["🗻","mount fuji"],["🏕️","camping"],["⛺","tent"],["🏠","house building"],["🏡","house with garden"],["🏘️","house buildings"],["🏚️","derelict house building"],["🏗️","building construction"],["🏭","factory"],["🏢","office building"],["🏬","department store"],["🏣","japanese post office"],["🏤","european post office"],["🏥","hospital"],["🏦","bank"],["🏨","hotel"],["🏪","convenience store"],["🏫","school"],["🏩","love hotel"],["💒","wedding"],["🏛️","classical building"],["⛪","church"],["🕌","mosque"]]},{"key":"objects","label":"Emoji: objects","icon":"⌚","emoji":[["⌚","watch"],["📱","mobile phone"],["📲","mobile phone with rightwards arrow at left"],["💻","personal computer"],["⌨️","keyboard"],["🖥️","desktop computer"],["🖨️","printer"],["🖱️","three button mouse"],["🖲️","trackball"],["🕹️","joystick"],["🗜️","compression"],["💽","minidisc"],["💾","floppy disk"],["💿","optical disc"],["📀","dvd"],["📼","videocassette"],["📷","camera"],["📸","camera with flash"],["📹","video camera"],["🎥","movie camera"],["📽️","film projector"],["🎞️","film frames"],["📞","telephone receiver"],["☎️","black telephone"],["📟","pager"],["📠","fax machine"],["📺","television"],["📻","radio"],["🎙️","studio microphone"],["🎚️","level slider"],["🎛️","control knobs"],["🧭","compass"],["⏱️","stopwatch"],["⏲️","timer clock"],["⏰","alarm clock"],["🕰️","mantelpiece clock"],["⌛","hourglass"],["⏳","hourglass with flowing sand"],["📡","satellite antenna"],["🔋","battery"],["🔌","electric plug"],["💡","electric light bulb"],["🔦","electric torch"],["🕯️","candle"],["🪔","diya lamp"],["🧯","fire extinguisher"],["🛢️","oil drum"],["💸","money with wings"],["💵","banknote with dollar sign"],["💴","banknote with yen sign"],["💶","banknote with euro sign"],["💷","banknote with pound sign"],["💰","money bag"],["💳","credit card"],["💎","gem stone"],["⚖️","scales"],["🧰","toolbox"],["🔧","wrench"],["🔨","hammer"],["⚒️","hammer and pick"],["🛠️","hammer and wrench"],["⛏️","pick"],["🔩","nut and bolt"],["⚙️","gear"],["🧱","brick"],["⛓️","chains"],["🧲","magnet"],["🔫","pistol"],["💣","bomb"],["🧨","firecracker"],["🪓","axe"],["🔪","hocho"],["🗡️","dagger knife"],["⚔️","crossed swords"],["🛡️","shield"],["🚬","smoking symbol"],["⚰️","coffin"],["⚱️","funeral urn"],["🏺","amphora"],["🔮","crystal ball"],["📿","prayer beads"],["🧿","nazar amulet"],["💈","barber pole"],["⚗️","alembic"],["🔭","telescope"],["🔬","microscope"],["🕳️","hole"],["🩹","adhesive bandage"],["🩺","stethoscope"],["💊","pill"],["💉","syringe"],["🩸","drop of blood"],["🧬","dna double helix"],["🦠","microbe"],["🧫","petri dish"],["🧪","test tube"],["🌡️","thermometer"],["🧹","broom"],["🧺","basket"],["🧻","roll of paper"],["🚽","toilet"],["🚰","potable water symbol"],["🚿","shower"],["🛁","bathtub"],["🛀","bath"],["🧼","bar of soap"],["🪒","razor"],["🧽","sponge"],["🧴","lotion bottle"],["🛎️","bellhop bell"],["🔑","key"],["🗝️","old key"],["🚪","door"],["🪑","chair"],["🛋️","couch and lamp"],["🛏️","bed"],["🛌","sleeping accommodation"],["🧸","teddy bear"],["🖼️","frame with picture"],["🛍️","shopping bags"],["🛒","shopping trolley"],["🎁","wrapped present"],["🎈","balloon"],["🎏","carp streamer"],["🎀","ribbon"],["🎊","confetti ball"],["🎉","party popper"],["🎎","japanese dolls"]]}];

/** What "frequent" offers before a person has picked any. */
export const DEFAULT_FREQUENT = ['👍', '❤️', '😂', '🎉', '🙏', '🔥', '👀', '✅', '😊', '😍', '🙂', '😅', '🤔', '👏', '💯',
    '😢', '😮', '🙌', '💪', '👌', '😎', '🚀', '⭐', '☕', '📌', '💡', '⚠️', '❌', '😉', '🤝'];

/** How many "frequent" keeps. */
export const FREQUENT_MAX = 30;

/** Where this browser keeps a person's frequent emoji. */
export const FREQUENT_KEY = 'pf-conv-emoji-recent';

/**
 * Emoji whose name matches a search — the team's own (as `:name:`, with their pictures), then the
 * shortcodes, then every category's by its English name — each once, the closest first: a name
 * starting with the words before one merely holding them.
 *
 * @param {string} query
 * @param {Record<string, string>} shortcodes name → emoji
 * @param {Record<string, string>} custom name → picture
 * @returns {Array<{emoji: string, name: string, src?: string}>}
 */
export function searchEmoji(query, shortcodes = {}, custom = {}, max = 48) {
    const q = String(query).trim().toLowerCase().replace(/^:|:$/g, '');
    if (q === '') {
        return [];
    }
    const found = [];
    const seen = new Set();
    const add = (emoji, name, src) => {
        if (!seen.has(emoji)) {
            seen.add(emoji);
            found.push(src ? { emoji, name, src } : { emoji, name });
        }
    };
    const rank = (name) => (name.startsWith(q) || name.split(/[\s_-]+/).some((w) => w.startsWith(q)) ? 0 : name.includes(q) ? 1 : 2);
    const lists = [
        Object.keys(custom).map((name) => [':' + name + ':', name, custom[name]]),
        Object.entries(shortcodes).filter(([name]) => !custom[name]).map(([name, glyph]) => [glyph, name]),
        CATEGORIES.flatMap((c) => c.emoji),
    ];
    for (const pass of [0, 1]) {
        for (const list of lists) {
            for (const [emoji, name, src] of list) {
                if (rank(name) === pass) {
                    add(emoji, name, src);
                }
            }
        }
    }

    return found.slice(0, max);
}

/**
 * A person's frequent emoji, the latest first — or the defaults where none are kept, or the
 * browser keeps nothing.
 *
 * @param {{getItem: Function}|undefined} storage
 * @returns {string[]}
 */
export function frequentEmoji(storage = globalThis.localStorage) {
    try {
        const kept = JSON.parse(storage?.getItem(FREQUENT_KEY) || '[]');

        return Array.isArray(kept) && kept.length ? kept.filter((e) => typeof e === 'string').slice(0, FREQUENT_MAX) : DEFAULT_FREQUENT;
    } catch {
        return DEFAULT_FREQUENT;
    }
}

/**
 * Remember an emoji picked: first among the frequent, once, at most {@see FREQUENT_MAX}.
 *
 * @param {string} emoji
 * @param {{getItem: Function, setItem: Function}|undefined} storage
 * @returns {string[]} The frequent ones now
 */
export function rememberEmoji(emoji, storage = globalThis.localStorage) {
    let kept = [];
    try {
        kept = JSON.parse(storage?.getItem(FREQUENT_KEY) || '[]');
    } catch {
        kept = [];
    }
    const now = [emoji, ...(Array.isArray(kept) ? kept : []).filter((e) => e !== emoji)].slice(0, FREQUENT_MAX);
    try {
        storage?.setItem(FREQUENT_KEY, JSON.stringify(now));
    } catch {
        /* private window: not kept */
    }

    return now;
}
