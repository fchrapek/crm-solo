import { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 200 50" xmlns="http://www.w3.org/2000/svg">
            <text
                x="100"
                y="37"
                textAnchor="middle"
                fill="currentColor"
                style={{ fontFamily: 'var(--font-family-sans)' }}
                fontWeight="700"
                fontSize="36"
                letterSpacing="-1"
            >
                CRM <tspan style={{ fill: 'var(--color-brand)' }}>Solo</tspan>
            </text>
        </svg>
    );
}
