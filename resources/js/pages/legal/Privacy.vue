<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { terms } from '@/routes';
import LegalPage from './LegalPage.vue';
</script>

<template>
    <LegalPage title="Privacy policy" updated-on="27 September 2026">
        <p>
            Lexi is a personal planner: money, tasks, calendar and the notes you
            keep beside them. That is unusually sensitive material, so this page
            says plainly what is stored, who can reach it, and what is never
            done with it.
        </p>

        <p>
            Lexi is operated by <strong>[LEGAL ENTITY NAME]</strong>, based in
            the Philippines. Questions about anything below go to
            <a href="mailto:[CONTACT EMAIL]">[CONTACT EMAIL]</a>.
        </p>

        <h2>What is collected</h2>

        <p>Only what you type in, plus what is needed to sign you in.</p>

        <ul>
            <li>
                <strong>Account details:</strong> your name, email address, and
                a one-way hash of your password. The password itself is never
                stored and cannot be recovered, only reset.
            </li>
            <li>
                <strong>Sign-in security:</strong> if you turn on two-factor
                authentication or register a passkey, the secrets backing them
                are stored and are never returned in any response.
            </li>
            <li>
                <strong>Everything you enter:</strong> accounts, debts, bills,
                budget envelopes and transactions; tasks; calendar events; and
                any images and captions you add to your vision board.
            </li>
            <li>
                <strong>Server logs:</strong> ordinary web server and error
                logs, which include IP addresses and are used for debugging and
                abuse prevention.
            </li>
        </ul>

        <h2>What is not done</h2>

        <ul>
            <li>
                Your data is <strong>never sold, rented or shared</strong> for
                advertising or any other commercial purpose.
            </li>
            <li>
                There are
                <strong
                    >no third-party analytics, advertising or tracking
                    scripts</strong
                >
                in Lexi. No behavioural profile is built, and nothing is sent to
                an ad network.
            </li>
            <li>
                Your financial figures are not used to train machine learning
                models.
            </li>
        </ul>

        <h2>Who can see your data</h2>

        <p>
            Your records are scoped to your account. Every request is checked
            against the signed-in user, and uploaded images have no public URL —
            they are served only through a signed, ownership-checked link.
        </p>

        <p>
            Operators of the service can technically reach stored data through
            the hosting provider and database, and will only do so where needed
            to run, debug or secure the service, or where the law requires it.
        </p>

        <h2>Third parties</h2>

        <p>
            Lexi relies on a small number of providers to run at all: a hosting
            provider, a database host, and an email provider used to send
            account mail such as verification and password reset messages. These
            providers process data on Lexi's behalf and are not permitted to use
            it for their own purposes.
        </p>

        <p>
            If you connect an external calendar, the events fetched from that
            account are stored in Lexi so they can appear alongside your own.
            That connection is optional and only runs when you set it up.
        </p>

        <h2>How long it is kept</h2>

        <p>
            Your data is kept for as long as your account exists. Deleting your
            account from
            <strong>Settings &rarr; Profile</strong> removes your records and
            your uploaded files. Deletion is immediate and cannot be undone, so
            export anything you want to keep first.
        </p>

        <p>
            Server logs and backups are retained for a limited period for
            operational reasons and then discarded.
        </p>

        <h2>Your choices</h2>

        <ul>
            <li>
                <strong>Correct it:</strong> everything you entered can be
                edited or deleted inside the app.
            </li>
            <li>
                <strong>Leave:</strong> delete your account at any time. No
                approval is needed and no reason is required.
            </li>
            <li>
                <strong>Ask:</strong> if you want a copy of your data, or want
                it corrected or erased, write to
                <a href="mailto:[CONTACT EMAIL]">[CONTACT EMAIL]</a> and it will
                be handled.
            </li>
        </ul>

        <h2>Security</h2>

        <p>
            Traffic is served over HTTPS, session cookies are encrypted and
            restricted to the site, passwords are hashed, and uploads are held
            on private storage. No system is perfect: if a breach affects your
            data, you will be told what happened and what to do about it.
        </p>

        <h2>Children</h2>

        <p>
            Lexi is not intended for anyone under 18, and accounts are not
            knowingly created for them.
        </p>

        <h2>Changes</h2>

        <p>
            If this policy changes in a way that materially affects you, the
            date above will change and you will be notified by email before it
            takes effect.
        </p>

        <template #footer>
            See also the
            <Link :href="terms()" class="underline underline-offset-4"
                >terms of service</Link
            >.
        </template>
    </LegalPage>
</template>
