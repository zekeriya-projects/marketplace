import amazonLogo from '../../img/logos/amazon-logo.png';
import hepsiburadaLogo from '../../img/logos/hepsiburada-logo.png';
import trendyolLogo from '../../img/logos/trendyol-logo.png';
import woocommerceLogo from '../../img/logos/woocommerce-logo.png';

const logos: Record<string, string> = {
    amazon: amazonLogo,
    hepsiburada: hepsiburadaLogo,
    trendyol: trendyolLogo,
    woocommerce: woocommerceLogo,
};

type Props = { code?: string | null; name?: string | null; size?: 'small' | 'medium' | 'large'; className?: string };

export const channelCode = (code?: string | null, name?: string | null) => {
    const value = `${code ?? ''} ${name ?? ''}`.toLocaleLowerCase('tr-TR');
    return Object.keys(logos).find(key => value.includes(key)) ?? code ?? 'channel';
};

export default function ChannelLogo({ code, name, size = 'medium', className = '' }: Props) {
    const resolved = channelCode(code, name);
    const src = logos[resolved];

    return src
        ? <span className={`provider-logo ${size} ${className}`} title={name ?? resolved}><img src={src} alt={name ?? resolved} /></span>
        : <span className={`provider-logo provider-logo-fallback ${size} ${className}`} title={name ?? 'Pazaryeri'}>{(name ?? code ?? '?').slice(0, 1).toUpperCase()}</span>;
}
