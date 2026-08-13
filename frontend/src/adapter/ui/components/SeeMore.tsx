type Props = {
  hidden: number
  expanded: boolean
  onMore: () => void
  onLess: () => void
}

export function SeeMore({ hidden, expanded, onMore, onLess }: Props) {
  if (hidden === 0 && !expanded) {
    return null
  }

  return (
    <div className="see-more">
      {hidden > 0 ? (
        <button className="ghost" type="button" onClick={onMore}>
          See more ({hidden})
        </button>
      ) : null}
      {expanded ? (
        <button className="ghost" type="button" onClick={onLess}>
          Show less
        </button>
      ) : null}
    </div>
  )
}
