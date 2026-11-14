import type { SummaryEmailState } from '../../../month-close/steps';

/** The month emails of a summary-email client, by period (billing_emails); null for a client whose months get a report. */
export type SummaryEmails = Record<string, SummaryEmailState>;
