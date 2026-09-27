<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { privacy } from '@/routes';
import LegalPage from './LegalPage.vue';
</script>

<template>
    <LegalPage title="Terms of service" updated-on="27 September 2026">
        <p>
            These terms cover your use of Lexi. Creating an account means you
            accept them. Lexi is operated by
            <strong>[LEGAL ENTITY NAME]</strong>, based in the Philippines.
        </p>

        <h2>What Lexi is</h2>

        <p>
            Lexi is a personal planning tool. It helps you record and organise
            your own money, tasks and plans. It does you the courtesy of doing
            arithmetic on the numbers you give it.
        </p>

        <p>
            <strong
                >Lexi is not a bank, lender, broker, accountant or financial
                adviser.</strong
            >
            Nothing it shows you — including payoff plans, forecasts and
            projections — is financial, tax or legal advice. The figures are
            calculated from what you entered, using simplified assumptions, and
            will not match what a lender or an accountant tells you. Decisions
            about your money remain yours, and you should take professional
            advice before making significant ones.
        </p>

        <p>
            Lexi does not connect to your bank, move money, or make payments on
            your behalf. Recording a payment in Lexi does not make it.
        </p>

        <h2>Your account</h2>

        <ul>
            <li>You need to be 18 or older to use Lexi.</li>
            <li>
                Give a real email address. It is how your account is verified
                and how you recover it if you lose your password.
            </li>
            <li>
                Keep your password to yourself, and turn on two-factor
                authentication if the data matters to you. You are responsible
                for what happens under your account.
            </li>
            <li>
                One account per person. Do not create accounts in bulk or by
                automated means.
            </li>
        </ul>

        <h2>What you may not do</h2>

        <ul>
            <li>
                Try to reach another user's data, or probe, scan or attack the
                service.
            </li>
            <li>
                Upload anything unlawful, or anything you do not have the right
                to upload.
            </li>
            <li>
                Use Lexi to store other people's personal or financial data
                without their knowledge.
            </li>
            <li>
                Resell access to Lexi, or use it in a way that degrades the
                service for anyone else.
            </li>
        </ul>

        <h2>Your content</h2>

        <p>
            What you put into Lexi stays yours. No ownership of it is claimed.
            The limited permission to store, process and display it back to you
            exists only so the service can function, and ends when you delete
            the content or your account.
        </p>

        <h2>Availability and backups</h2>

        <p>
            Lexi is provided as it is, without a guarantee of uptime. Features
            may change, and the service may be interrupted for maintenance.
            Backups are kept for operational recovery, but they are not a
            substitute for your own copy of anything you cannot afford to lose.
        </p>

        <h2>Ending it</h2>

        <p>
            You can delete your account at any time from
            <strong>Settings &rarr; Profile</strong>. Deletion is immediate and
            irreversible.
        </p>

        <p>
            Your access may be suspended or ended if you break these terms, or
            if keeping the account open would put the service or other users at
            risk. Where it is reasonable to do so, you will be told why and
            given a chance to retrieve your data first.
        </p>

        <h2>Liability</h2>

        <p>
            To the fullest extent the law allows, Lexi and its operator are not
            liable for indirect or consequential loss, or for financial
            decisions you make while using it. Nothing here limits liability
            that cannot legally be limited.
        </p>

        <h2>Changes</h2>

        <p>
            These terms may change. If a change materially affects you, the date
            above will change and you will be notified by email before it takes
            effect. Continuing to use Lexi after that means you accept the
            revised terms.
        </p>

        <h2>Governing law</h2>

        <p>
            These terms are governed by the laws of the Republic of the
            Philippines.
        </p>

        <template #footer>
            See also the
            <Link :href="privacy()" class="underline underline-offset-4"
                >privacy policy</Link
            >.
        </template>
    </LegalPage>
</template>
