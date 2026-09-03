import styles from './heading.module.css';

export default function Heading({ title, description }: { title: string; description?: string }) {
    return (
        <div className={styles.container}>
            <h2 className={styles.title}>{title}</h2>
            {description && <p className={styles.description}>{description}</p>}
        </div>
    );
}
