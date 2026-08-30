<?php

declare(strict_types=1);

namespace Pramnos\Email;

/**
 * The mail body store, which is now the body store.
 *
 * It was written for `mails` and lived here. `messages` has the same problem — a body that is the
 * whole size of the table and the one column nobody queries — and solving it twice would have
 * meant two stores, two garbage collections and two answers to what a GDPR erasure has to remove.
 * So the implementation moved to {@see \Pramnos\Storage\BodyStore}, which is where a facility
 * serving two subsystems belongs, and this name stays behind pointing at it.
 *
 * Nothing here is deprecated in the sense of *going away*: `Email\BodyStore::bodyOf($row)` in an
 * application keeps working and keeps meaning what it meant. New code should name the class in
 * `Storage`, because a message body has nothing to do with email.
 *
 * The configuration key is still `mail.body_store`. Renaming it would break every installation
 * that has set it, to buy tidiness in a config file nobody reads twice.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class BodyStore extends \Pramnos\Storage\BodyStore
{
}
