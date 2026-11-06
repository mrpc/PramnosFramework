{{-- A small link back to the site, under a public auth card (plain-CSS theme).

Somebody who landed on the sign-in, registration or password screens from a link
otherwise has no way back to the site's home page short of editing the address.
Inserted right after the card on the pre-sign-in screens:

    <?php $this->insert('../partials/auth_home_link'); ?> --}}
<p style="text-align:center;margin-top:12px;font-size:13px">
    <a href="{{ sURL }}" style="color:#888">{{ t('Back to the home page') }}</a>
</p>
